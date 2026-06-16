<?php

declare(strict_types=1);

namespace Botbye\Common;

/**
 * Once-per-process init-handshake guard shared by the protection and phishing clients.
 *
 * PHP re-instantiates the client per request, so a naive constructor handshake would fire on every
 * request. This trait suppresses that with two layers, both keyed by a caller-supplied guard key
 * (a hash of endpoint + key) so distinct configs in one process each init independently:
 *
 *  - a per-class static map ({@see $initGuardDone}) short-circuits within a single process, and
 *  - a per-(config) flag file guards across the worker pool, claimed under an exclusive {@code flock}.
 *
 * The blocking network handshake runs OUTSIDE the file lock: the flag is claimed first, the lock is
 * released, then the handshake fires. This keeps the critical section to fast local file I/O so
 * concurrent workers never serialize on the lock around a network round-trip (a hot-path stall risk).
 *
 * Each {@code use}-ing class gets its own copy of the static map, so the protection and phishing
 * guards do not collide.
 */
trait InitGuard
{
    /** @var array<string, bool> guard keys already handled in this process */
    private static array $initGuardDone = [];

    /**
     * Runs $handshake at most once per process per $guardKey. Any work the caller does inside
     * $handshake should be best-effort: this method never throws on guard/lock failures.
     *
     * @param callable(): void $handshake
     */
    private function runInitGuardOnce(string $guardKey, string $flagFile, callable $handshake): void
    {
        if (self::$initGuardDone[$guardKey] ?? false) {
            return;
        }
        // Mark handled up front: we attempt the handshake at most once per process incarnation,
        // regardless of its outcome (it is best-effort and swallows its own errors).
        self::$initGuardDone[$guardKey] = true;

        if ($this->claimInit($flagFile)) {
            $handshake();
        }
    }

    /**
     * Decides whether this process must run the handshake and, if so, claims the flag file before
     * returning so the (unlocked) handshake does not race other workers. Returns true to run.
     */
    private function claimInit(string $flagFile): bool
    {
        $lockHandle = @fopen($flagFile . '.lock', 'c');
        if ($lockHandle === false) {
            // Cannot create the lock file — fall back to running the handshake (best effort).
            return true;
        }

        try {
            if (!@flock($lockHandle, LOCK_EX)) {
                return true;
            }

            clearstatcache(true, $flagFile);

            $token = $this->initGuardProcessToken();

            if (is_file($flagFile)) {
                $content = (string)@file_get_contents($flagFile);
                if (preg_match('/^token:(.+)$/m', $content, $m) && $m[1] === $token) {
                    return false;
                }
            }

            // Claim the flag now (while holding the lock) so the network handshake can run after the
            // lock is released without another worker also deciding it must handshake.
            @file_put_contents($flagFile, "token:$token\nat:" . time() . "\n", LOCK_EX);

            return true;
        } finally {
            @flock($lockHandle, LOCK_UN);
            @fclose($lockHandle);
        }
    }

    /**
     * Identifies this process incarnation for the init guard. The pid alone is not enough: a process
     * manager (or a container, where the SAPI is always pid 1) reuses pids across restarts, so a stale
     * guard file left in a persistent temp dir would suppress the handshake for the new process. Pairing
     * the pid with the kernel process start time (field 22 of {@code /proc/self/stat}) makes the token
     * unique per incarnation; on platforms without procfs it degrades to pid-only.
     */
    private function initGuardProcessToken(): string
    {
        $pid = (string)getmypid();

        $stat = @file_get_contents('/proc/self/stat');
        if ($stat !== false) {
            // comm (field 2) is parenthesised and may contain spaces, so parse after the final ')':
            // the remaining whitespace-separated fields start at field 3 (state), making starttime
            // (field 22) the element at index 19.
            $rparen = strrpos($stat, ')');
            if ($rparen !== false) {
                $rest = preg_split('/\s+/', trim(substr($stat, $rparen + 1)));
                if (is_array($rest) && isset($rest[19]) && $rest[19] !== '') {
                    return $pid . '-' . $rest[19];
                }
            }
        }

        return $pid;
    }

    /**
     * Resolves the guard flag-file path: the explicit override if set, else a temp-dir file named by
     * the guard key and the given prefix (so protection and phishing flags never collide).
     */
    private function initGuardFlagFilePath(?string $override, string $prefix, string $guardKey): string
    {
        if ($override !== null && $override !== '') {
            return $override;
        }

        $dir = rtrim((string)sys_get_temp_dir(), DIRECTORY_SEPARATOR);

        return $dir . DIRECTORY_SEPARATOR . $prefix . $guardKey . '.flag';
    }
}
