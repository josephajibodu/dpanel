<?php

namespace App\Models;

use Database\Factories\TerminalSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One browser terminal session on a server. The row is issued with a
 * single-use token the terminal gateway redeems to open the SSH connection,
 * and it doubles as the audit record of who opened a shell, where and when.
 */
class TerminalSession extends Model
{
    /** @use HasFactory<TerminalSessionFactory> */
    use HasFactory;

    /**
     * How long an issued token stays redeemable.
     */
    public const TOKEN_TTL_SECONDS = 60;

    protected $fillable = [
        'server_id',
        'user_id',
        'token_hash',
        'ip_address',
        'expires_at',
        'started_at',
        'ended_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'token_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * Issue a new session for the user on the server. Only a hash of the
     * token is stored; the plaintext is returned once, for the browser.
     *
     * @return array{session: self, token: string}
     */
    public static function issue(Server $server, User $user, ?string $ipAddress): array
    {
        $token = Str::random(64);

        $session = self::create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'token_hash' => self::hashToken($token),
            'ip_address' => $ipAddress,
            'expires_at' => now()->addSeconds(self::TOKEN_TTL_SECONDS),
        ]);

        return ['session' => $session, 'token' => $token];
    }

    /**
     * Mark the session for this token as started and return it, or null when
     * the token is unknown, expired or was already used. The update is
     * conditional so two simultaneous redeems cannot both succeed.
     */
    public static function redeem(string $token): ?self
    {
        return DB::transaction(function () use ($token): ?self {
            $session = self::where('token_hash', self::hashToken($token))
                ->whereNull('started_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            $session?->update(['started_at' => now()]);

            return $session;
        });
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
