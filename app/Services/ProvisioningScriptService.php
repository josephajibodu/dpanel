<?php

namespace App\Services;

use App\Models\Server;

class ProvisioningScriptService
{
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
        ]);
    }

    /**
     * Build the provisioning script with variables.
     *
     * @param  array<string, string>  $variables
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

echo "=== Starting ServerForge Provisioning ==="
echo "PHP Version: $PHP_VERSION"
echo "Database: $DATABASE_TYPE"

# --- Wait for cloud-init to complete ---
echo "=== Waiting for cloud-init to complete ==="
cloud-init status --wait 2>/dev/null || true

# --- Create Swap Space ---
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

# --- Create forge user ---
echo "=== Creating forge user ==="
if ! id "forge" &>/dev/null; then
    useradd -m -s /bin/bash forge
    echo "forge:$SUDO_PASSWORD" | chpasswd
    usermod -aG sudo forge
    echo "forge ALL=(ALL) NOPASSWD:ALL" >> /etc/sudoers.d/forge
    chmod 440 /etc/sudoers.d/forge
fi

# --- SSH Configuration ---
echo "=== Configuring SSH ==="
mkdir -p /home/forge/.ssh
if [ -f /root/.ssh/authorized_keys ]; then
    cp /root/.ssh/authorized_keys /home/forge/.ssh/
fi
chown -R forge:forge /home/forge/.ssh
chmod 700 /home/forge/.ssh
chmod 600 /home/forge/.ssh/authorized_keys 2>/dev/null || true

# --- Install Nginx ---
echo "=== Installing Nginx ==="
wait_for_apt && apt-get -o DPkg::Lock::Timeout=60 install -y nginx
systemctl enable nginx
systemctl start nginx

# --- Install PHP ---
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

# --- Install Database ---
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
fi

# --- Install Redis ---
echo "=== Installing Redis ==="
wait_for_apt && apt-get -o DPkg::Lock::Timeout=60 install -y redis-server
systemctl enable redis-server
systemctl start redis-server

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
mkdir -p /home/forge/sites
chown -R forge:forge /home/forge

# --- Configure PHP-FPM pool ---
echo "=== Configuring PHP-FPM ==="
cat > /etc/php/${PHP_VERSION}/fpm/pool.d/forge.conf <<EOF
[forge]
user = forge
group = forge
listen = /run/php/php${PHP_VERSION}-fpm-forge.sock
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

echo "=== Provisioning complete! ==="
BASH;

        // Replace variables
        foreach ($variables as $key => $value) {
            $script = str_replace("{{{$key}}}", $value, $script);
        }

        return $script;
    }
}
