<?php

declare(strict_types=1);

namespace Box\Mod\Quizontalavatar;

use FOSSBilling\InformationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** FOSSBilling 0.7-compatible profile avatar service. */
class Service implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void { $this->di = $di; }
    public function getDi(): ?\Pimple\Container { return $this->di; }

    public function install(): bool
    {
        $this->ensureUploadDirectory();
        return true;
    }

    public function uninstall(): bool
    {
        // Uploaded avatars are deliberately retained.
        return true;
    }

    /** Absolute path to a client's avatar file, or null when none is set. */
    public function avatarPath(int $clientId): ?string
    {
        if ($clientId < 1) return null;
        foreach (['jpg', 'jpeg', 'png', 'webp', 'gif'] as $ext) {
            $path = $this->ensureUploadDirectory().DIRECTORY_SEPARATOR.'avatar-'.$clientId.'.'.$ext;
            if (is_file($path) && is_readable($path)) return $path;
        }
        return null;
    }

    /**
     * Validate and store a new avatar for a client, replacing any previous one.
     * Returns the URL (relative) to the stored avatar.
     */
    public function saveAvatar(int $clientId, ?UploadedFile $file): string
    {
        if ($clientId < 1) throw new InformationException('Client not found.');
        $this->validateUpload($file);

        // Remove any previous avatar so there is never more than one per client.
        $this->deleteAvatar($clientId);

        $ext = $this->extensionForMime($file->getMimeType());
        $storedName = 'avatar-'.$clientId.'.'.$ext;
        $file->move($this->ensureUploadDirectory(), $storedName);

        return 'quizontalavatar/avatar/'.$clientId.'?v='.time();
    }

    /** Remove a client's avatar (used before replacing). */
    public function deleteAvatar(int $clientId): void
    {
        $path = $this->avatarPath($clientId);
        if ($path !== null) @unlink($path);
    }

    /** Output headers + bytes for an avatar file. */
    public function serveAvatar(int $clientId): void
    {
        $path = $this->avatarPath($clientId);
        if ($path === null) throw new InformationException('Avatar not found.', null, 404);

        $mime = $this->mimeForExtension(pathinfo($path, PATHINFO_EXTENSION));
        header('Content-Type: '.$mime);
        header('Content-Length: '.(string) filesize($path));
        header('Cache-Control: private, max-age=0');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
    }

    private function validateUpload(?UploadedFile $file): void
    {
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new InformationException('A valid image file is required.');
        }
        $max = 4 * 1024 * 1024; // 4 MB
        if ($file->getSize() <= 0 || $file->getSize() > $max) {
            throw new InformationException('The image is too large (maximum 4 MB).');
        }
        if (!in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            throw new InformationException('Only JPG, PNG, WEBP and GIF images are accepted.');
        }
    }

    private function extensionForMime(string $mime): string
    {
        return ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? 'png';
    }

    private function mimeForExtension(string $ext): string
    {
        return ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'][$ext] ?? 'image/png';
    }

    private function ensureUploadDirectory(): string
    {
        $dir = PATH_DATA.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'quizontal-avatar';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create the avatar directory.');
        }
        return $dir;
    }
}
