<?php
declare(strict_types=1);

/**
 * Derives a stable owner identifier ("sub") for the chat agent from a session user.
 * Some customer accounts have no numeric `id` (only a Mongo ObjectId, or nothing), so
 * fall back to the ObjectId string or a hash of the email instead of collapsing to "0".
 */
final class ChatAgentIdentity
{
    public static function subFor(array $user): string
    {
        $id = $user['id'] ?? null;
        if (is_int($id)) {
            if ($id > 0) return (string)$id;
        } elseif (is_string($id)) {
            $id = trim($id);
            if ($id !== '' && ctype_digit($id)) {
                $n = (int)$id;
                if ($n > 0) return (string)$n;
            } elseif ($id !== '' && $id !== '0' && !is_numeric($id)) {
                return $id;
            }
        }

        $email = strtolower(trim((string)($user['email'] ?? '')));
        if ($email !== '') {
            return 'em:' . substr(hash('sha256', $email), 0, 32);
        }
        return '';
    }
}
