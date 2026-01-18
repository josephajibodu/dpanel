<?php

namespace App\Services;

use App\Enums\ProvisioningStep;
use App\Models\Server;

class ProvisioningScriptService
{
    /**
     * The marker prefix used to signal step changes in provisioning output.
     * Format: ###STEP:X### where X is the step number.
     */
    public const STEP_MARKER_PREFIX = '###STEP:';

    public const STEP_MARKER_SUFFIX = '###';

    /**
     * The marker prefix used to output data from the provisioning script.
     * Format: ###DATA:key=value###
     */
    public const DATA_MARKER_PREFIX = '###DATA:';

    public const DATA_MARKER_SUFFIX = '###';

    /**
     * Generate the provisioning script for a server.
     */
    public function generate(Server $server): string
    {
        $databasePassword = $server->credentials()
            ->where('type', 'database_password')
            ->first()?->value ?? '';

        $sudoPassword = $server->credentials()
            ->where('type', 'sudo_password')
            ->first()?->value ?? '';

        $phpVersion = $server->php_version;
        $databaseType = $server->database_type;

        return $this->buildScript([
            'PHP_VERSION' => $phpVersion,
            'DATABASE_TYPE' => $databaseType,
            'DB_PASSWORD' => $databasePassword,
            'SUDO_PASSWORD' => $sudoPassword,
            'SERVER_USER' => config('server.user'),
            'STEP_PREPARING' => ProvisioningStep::PreparingServer->value,
            'STEP_SWAP' => ProvisioningStep::ConfiguringSwap->value,
            'STEP_BASE_DEPS' => ProvisioningStep::InstallingBaseDependencies->value,
            'STEP_PHP' => ProvisioningStep::InstallingPhp->value,
            'STEP_NGINX' => ProvisioningStep::InstallingNginx->value,
            'STEP_DATABASE' => ProvisioningStep::InstallingDatabase->value,
            'STEP_REDIS' => ProvisioningStep::InstallingRedis->value,
            'STEP_FINAL' => ProvisioningStep::MakingFinalTouches->value,
            'STEP_FINISHED' => ProvisioningStep::Finished->value,
        ]);
    }

    /**
     * Parse a line of output and extract the step number if it's a step marker.
     */
    public static function parseStepMarker(string $line): ?int
    {
        if (str_starts_with($line, self::STEP_MARKER_PREFIX) && str_ends_with($line, self::STEP_MARKER_SUFFIX)) {
            $stepNumber = substr(
                $line,
                strlen(self::STEP_MARKER_PREFIX),
                -strlen(self::STEP_MARKER_SUFFIX)
            );

            if (is_numeric($stepNumber)) {
                return (int) $stepNumber;
            }
        }

        return null;
    }

    /**
     * Parse a line of output and extract key-value data if it's a data marker.
     *
     * @return array{key: string, value: string}|null
     */
    public static function parseDataMarker(string $line): ?array
    {
        if (str_starts_with($line, self::DATA_MARKER_PREFIX) && str_ends_with($line, self::DATA_MARKER_SUFFIX)) {
            $data = substr(
                $line,
                strlen(self::DATA_MARKER_PREFIX),
                -strlen(self::DATA_MARKER_SUFFIX)
            );

            $parts = explode('=', $data, 2);
            if (count($parts) === 2) {
                return [
                    'key' => $parts[0],
                    'value' => $parts[1],
                ];
            }
        }

        return null;
    }

    /**
     * Build the provisioning script with variables.
     *
     * @param  array<string, string|int>  $variables
     */
    private function buildScript(array $variables): string
    {
        $script = <<<'BASH'
#!/bin/bash
set -e

export DEBIAN_FRONTEND=noninteractive

# Variables
PHP_VERSION="{{PHP_VERSION}}"
DATABASE_TYPE="{{DATABASE_TYPE}}"
DB_PASSWORD="{{DB_PASSWORD}}"
SUDO_PASSWORD="{{SUDO_PASSWORD}}"
SERVER_USER="{{SERVER_USER}}"

# Step markers for progress tracking
step_marker() {
    echo "###STEP:$1###"
}

# Data markers for capturing server info
data_marker() {
    echo "###DATA:$1=$2###"
}

echo "=== Starting ServerForge Provisioning ==="
echo "PHP Version: $PHP_VERSION"
echo "Database: $DATABASE_TYPE"

# --- Capture Ubuntu version ---
UBUNTU_VERSION=$(lsb_release -rs 2>/dev/null || cat /etc/os-release | grep VERSION_ID | cut -d'"' -f2)
data_marker "ubuntu_version" "$UBUNTU_VERSION"
echo "Ubuntu Version: $UBUNTU_VERSION"

# --- Wait for cloud-init to complete ---
echo "=== Waiting for cloud-init to complete ==="
cloud-init status --wait 2>/dev/null || true

# =========================================
# STEP: Preparing Server
# =========================================
step_marker {{STEP_PREPARING}}

# --- Create server user ---
echo "=== Creating $SERVER_USER user ==="
if ! id "$SERVER_USER" &>/dev/null; then
    useradd -m -s /bin/bash $SERVER_USER
    echo "$SERVER_USER:$SUDO_PASSWORD" | chpasswd
    usermod -aG sudo $SERVER_USER
    echo "$SERVER_USER ALL=(ALL) NOPASSWD:ALL" >> /etc/sudoers.d/$SERVER_USER
    chmod 440 /etc/sudoers.d/$SERVER_USER
fi

# --- SSH Configuration ---
echo "=== Configuring SSH ==="
mkdir -p /home/$SERVER_USER/.ssh
if [ -f /root/.ssh/authorized_keys ]; then
    cp /root/.ssh/authorized_keys /home/$SERVER_USER/.ssh/
fi
chown -R $SERVER_USER:$SERVER_USER /home/$SERVER_USER/.ssh
chmod 700 /home/$SERVER_USER/.ssh
chmod 600 /home/$SERVER_USER/.ssh/authorized_keys 2>/dev/null || true

# --- Generate server's local SSH key (for deployments) ---
echo "=== Generating server SSH key ==="
if [ ! -f /home/$SERVER_USER/.ssh/id_ed25519 ]; then
    ssh-keygen -t ed25519 -f /home/$SERVER_USER/.ssh/id_ed25519 -N "" -C "$SERVER_USER@$(hostname)"
    chown $SERVER_USER:$SERVER_USER /home/$SERVER_USER/.ssh/id_ed25519 /home/$SERVER_USER/.ssh/id_ed25519.pub
    chmod 600 /home/$SERVER_USER/.ssh/id_ed25519
    chmod 644 /home/$SERVER_USER/.ssh/id_ed25519.pub
fi

# Output the local public key for storage
LOCAL_PUBLIC_KEY=$(cat /home/$SERVER_USER/.ssh/id_ed25519.pub)
data_marker "local_public_key" "$LOCAL_PUBLIC_KEY"

# =========================================
# STEP: Configuring Swap
# =========================================
step_marker {{STEP_SWAP}}

echo "=== Setting up swap space ==="
if [ ! -f /swapfile ]; then
    fallocate -l 1G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    echo '/swapfile none swap sw 0 0' >> /etc/fstab
    # Reduce swappiness for better performance
    echo 'vm.swappiness=10' >> /etc/sysctl.conf
    sysctl vm.swappiness=10
    echo "Swap created successfully"
else
    echo "Swap already exists"
fi

# =========================================
# STEP: Installing Base Dependencies
# =========================================
step_marker {{STEP_BASE_DEPS}}

# --- Helper function to wait for apt/dpkg locks ---
wait_for_apt() {
    while fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1 || \
          fuser /var/lib/dpkg/lock >/dev/null 2>&1 || \
          fuser /var/lib/apt/lists/lock >/dev/null 2>&1 || \
          fuser /var/cache/apt/archives/lock >/dev/null 2>&1; do
        echo "Waiting for apt/dpkg lock..."
        sleep 2
    done
}

# Fix any interrupted package operations
echo "=== Fixing any interrupted package operations ==="
wait_for_apt
dpkg --configure -a || true

# --- System Update ---
echo "=== Updating system packages ==="
wait_for_apt && apt-get -o DPkg::Lock::Timeout=60 update
wait_for_apt && apt-get -o DPkg::Lock::Timeout=60 upgrade -y

# =========================================
# STEP: Installing PHP
# =========================================
step_marker {{STEP_PHP}}

echo "=== Installing PHP $PHP_VERSION ==="
wait_for_apt && apt-get -o DPkg::Lock::Timeout=60 install -y software-properties-common
add-apt-repository -y ppa:ondrej/php
wait_for_apt && apt-get -o DPkg::Lock::Timeout=60 update
wait_for_apt && apt-get -o DPkg::Lock::Timeout=60 install -y \
    php${PHP_VERSION}-fpm \
    php${PHP_VERSION}-cli \
    php${PHP_VERSION}-common \
    php${PHP_VERSION}-mysql \
    php${PHP_VERSION}-pgsql \
    php${PHP_VERSION}-sqlite3 \
    php${PHP_VERSION}-redis \
    php${PHP_VERSION}-curl \
    php${PHP_VERSION}-gd \
    php${PHP_VERSION}-mbstring \
    php${PHP_VERSION}-xml \
    php${PHP_VERSION}-zip \
    php${PHP_VERSION}-bcmath \
    php${PHP_VERSION}-intl \
    php${PHP_VERSION}-readline \
    php${PHP_VERSION}-imagick

systemctl enable php${PHP_VERSION}-fpm
systemctl start php${PHP_VERSION}-fpm

# =========================================
# STEP: Installing Nginx
# =========================================
step_marker {{STEP_NGINX}}

echo "=== Installing Nginx ==="
wait_for_apt && apt-get -o DPkg::Lock::Timeout=60 install -y nginx
systemctl enable nginx
systemctl start nginx

# =========================================
# STEP: Installing Database
# =========================================
step_marker {{STEP_DATABASE}}

if [ "$DATABASE_TYPE" = "mysql" ]; then
    echo "=== Installing MySQL 8 ==="
    wait_for_apt && apt-get -o DPkg::Lock::Timeout=60 install -y mysql-server
    systemctl enable mysql
    systemctl start mysql
    # Use sudo mysql for Ubuntu's default auth_socket authentication
    sudo mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '$DB_PASSWORD';" || echo "MySQL root password already configured"
    sudo mysql -e "FLUSH PRIVILEGES;" 2>/dev/null || true
elif [ "$DATABASE_TYPE" = "postgresql" ]; then
    echo "=== Installing PostgreSQL ==="
    wait_for_apt && apt-get -o DPkg::Lock::Timeout=60 install -y postgresql postgresql-contrib
    systemctl enable postgresql
    systemctl start postgresql
    sudo -u postgres psql -c "ALTER USER postgres PASSWORD '$DB_PASSWORD';"
elif [ "$DATABASE_TYPE" = "mariadb" ]; then
    echo "=== Installing MariaDB ==="
    wait_for_apt && apt-get -o DPkg::Lock::Timeout=60 install -y mariadb-server
    systemctl enable mariadb
    systemctl start mariadb
    # Use sudo mysql for Ubuntu's default auth_socket authentication
    sudo mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '$DB_PASSWORD';" || echo "MariaDB root password already configured"
    sudo mysql -e "FLUSH PRIVILEGES;" 2>/dev/null || true
else
    echo "=== No database selected, skipping database installation ==="
fi

# =========================================
# STEP: Installing Redis
# =========================================
step_marker {{STEP_REDIS}}

echo "=== Installing Redis ==="
wait_for_apt && apt-get -o DPkg::Lock::Timeout=60 install -y redis-server
systemctl enable redis-server
systemctl start redis-server

# =========================================
# STEP: Making Final Touches
# =========================================
step_marker {{STEP_FINAL}}

# --- Install Composer ---
echo "=== Installing Composer ==="
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# --- Install Node.js 20 ---
echo "=== Installing Node.js 20 ==="
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
wait_for_apt && apt-get -o DPkg::Lock::Timeout=60 install -y nodejs

# --- Install Supervisor ---
echo "=== Installing Supervisor ==="
wait_for_apt && apt-get -o DPkg::Lock::Timeout=60 install -y supervisor
systemctl enable supervisor
systemctl start supervisor

# --- Install Git ---
echo "=== Installing Git ==="
wait_for_apt && apt-get -o DPkg::Lock::Timeout=60 install -y git

# --- Configure Firewall ---
echo "=== Configuring Firewall ==="
wait_for_apt && apt-get -o DPkg::Lock::Timeout=60 install -y ufw
ufw allow 22
ufw allow 80
ufw allow 443
ufw --force enable

# --- Create sites directory ---
echo "=== Setting up directories ==="
mkdir -p /home/$SERVER_USER/sites
chown -R $SERVER_USER:$SERVER_USER /home/$SERVER_USER

# --- Configure PHP-FPM pool ---
echo "=== Configuring PHP-FPM ==="
cat > /etc/php/${PHP_VERSION}/fpm/pool.d/$SERVER_USER.conf <<EOF
[$SERVER_USER]
user = $SERVER_USER
group = $SERVER_USER
listen = /run/php/php${PHP_VERSION}-fpm-$SERVER_USER.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 5
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
EOF

systemctl restart php${PHP_VERSION}-fpm

# --- Cleanup ---
echo "=== Cleaning up ==="
wait_for_apt && apt-get -o DPkg::Lock::Timeout=60 autoremove -y
apt-get clean

# =========================================
# STEP: Finished
# =========================================
step_marker {{STEP_FINISHED}}

echo "=== Provisioning complete! ==="
BASH;

        // Replace variables
        foreach ($variables as $key => $value) {
            $script = str_replace("{{{$key}}}", (string) $value, $script);
        }

        return $script;
    }
}
