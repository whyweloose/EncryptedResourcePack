<?php

declare(strict_types=1);

namespace srcResourcesPack\service;

use InvalidArgumentException;
use LogicException;
use pocketmine\resourcepacks\ZippedResourcePack;
use srcResourcesPack\ResourcesPackPlugin;

use function array_filter;
use function array_diff;
use function array_merge;
use function array_values;
use function basename;
use function bin2hex;
use function chmod;
use function count;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function feof;
use function fclose;
use function fopen;
use function fread;
use function function_exists;
use function fwrite;
use function hash;
use function hash_file;
use function is_dir;
use function is_file;
use function is_resource;
use function is_string;
use function in_array;
use function mkdir;
use function openssl_encrypt;
use function random_bytes;
use function rename;
use function scandir;
use function strtolower;
use function substr;
use function strlen;
use function preg_match;
use function usort;
use function unlink;

final class ResourcePackService
{
	private string $packsDirectory;
	private string $encryptedDirectory;
	/** @var \srcResourcesPack\resource\EncryptedResourcePack[] */
	private array $resourcePacks = [];
	/** @var string[] */
	private array $encryptionKeys = [];

	public function __construct(private ResourcesPackPlugin $plugin)
	{
		$this->packsDirectory = $plugin->getDataFolder() . "packs" . DIRECTORY_SEPARATOR;
		$this->encryptedDirectory = $plugin->getDataFolder() . "encrypted" . DIRECTORY_SEPARATOR;
		$this->ensureDirectory($this->packsDirectory);
		$this->ensureDirectory($this->encryptedDirectory);
		$this->loadPacks();
		$plugin->getLogger()->info("Loaded " . count($this->resourcePacks) . " encrypted resource pack(s) from plugin data");
	}

	public function getPlugin(): ResourcesPackPlugin
	{
		return $this->plugin;
	}

	/** @return \srcResourcesPack\resource\EncryptedResourcePack[] */
	public function getResourcePacks(): array
	{
		return $this->resourcePacks;
	}

	/** @return string[] */
	public function getEncryptionKeys(): array
	{
		return $this->encryptionKeys;
	}

	private function ensureDirectory(string $path): void
	{
		if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
			throw new LogicException("Could not create resource pack directory: " . $path);
		}
	}

	private function loadPacks(): void
	{
		$files = scandir($this->packsDirectory);
		if ($files === false) {
			throw new LogicException("Could not scan resource pack directory: " . $this->packsDirectory);
		}
		$files = array_values(array_filter($files, static fn(string $file): bool => preg_match('/\\.(zip|mcpack)$/i', $file) === 1));
		usort($files, 'strcasecmp');
		$files = $this->applyConfiguredOrder($files);

		$ids = [];
		foreach ($files as $file) {
			$sourcePath = $this->packsDirectory . $file;
			try {
				$metadata = new ZippedResourcePack($sourcePath);
				$id = strtolower($metadata->getPackId());
				if (isset($ids[$id])) {
					throw new InvalidArgumentException("Duplicate pack UUID " . $metadata->getPackId());
				}
				$key = $this->loadOrCreateKey($sourcePath);
				$encryptedPath = $this->prepareEncryptedPack($sourcePath, $metadata->getPackId(), $key);
				$pack = new \srcResourcesPack\resource\EncryptedResourcePack($metadata, $encryptedPath, $key);
				$ids[$id] = true;
				$this->resourcePacks[] = $pack;
				$this->encryptionKeys[$id] = $key;
				$this->plugin->getLogger()->debug("Prepared encrypted resource pack " . basename($sourcePath) . " (" . $metadata->getPackId() . ")");
			} catch (\Throwable $error) {
				$this->plugin->getLogger()->error("Could not load resource pack " . $file . ": " . $error->getMessage());
			}
		}
	}

	/** @param string[] $files @return string[] */
	private function applyConfiguredOrder(array $files): array
	{
		$configured = $this->plugin->getConfig()->get("resource_stack", []);
		if (!is_array($configured) || $configured === []) {
			return $files;
		}
		$ordered = [];
		foreach ($configured as $name) {
			if (is_string($name) && in_array($name, $files, true)) {
				$ordered[] = $name;
			}
		}
		return array_merge($ordered, array_values(array_diff($files, $ordered)));
	}

	private function loadOrCreateKey(string $sourcePath): string
	{
		$keyPath = $sourcePath . ".key";
		if (file_exists($keyPath)) {
			$key = file_get_contents($keyPath);
			if ($key !== false && preg_match('/\\A[\\x20-\\x7E]{32}\\z/D', $key) === 1) {
				return $key;
			}
		}

		$key = bin2hex(random_bytes(16));
		if (file_put_contents($keyPath, $key, LOCK_EX) === false) {
			throw new LogicException("Could not write resource pack key: " . $keyPath);
		}
		@chmod($keyPath, 0600);
		return $key;
	}

	private function prepareEncryptedPack(string $sourcePath, string $packId, string $key): string
	{
		if (!function_exists("openssl_encrypt")) {
			throw new LogicException("The OpenSSL PHP extension is required to encrypt resource packs");
		}
		$sourceHash = hash_file("sha256", $sourcePath);
		if ($sourceHash === false) {
			throw new LogicException("Could not hash source resource pack: " . $sourcePath);
		}
		$cacheKey = hash("sha256", $sourceHash . "\0" . $key);
		$base = hash("sha256", strtolower($packId));
		$encryptedPath = $this->encryptedDirectory . $base . ".pack";
		$metadataPath = $encryptedPath . ".meta";
		if (is_file($encryptedPath) && is_file($metadataPath) && file_get_contents($metadataPath) === $cacheKey) {
			return $encryptedPath;
		}

		$tempPath = $encryptedPath . ".tmp-" . bin2hex(random_bytes(6));
		$this->encryptFile($sourcePath, $tempPath, $key);
		if (!rename($tempPath, $encryptedPath)) {
			@unlink($tempPath);
			throw new LogicException("Could not replace encrypted resource pack: " . $encryptedPath);
		}
		if (file_put_contents($metadataPath, $cacheKey, LOCK_EX) === false) {
			throw new LogicException("Could not write encrypted resource pack cache metadata");
		}
		return $encryptedPath;
	}

	private function encryptFile(string $sourcePath, string $destinationPath, string $key): void
	{
		$input = fopen($sourcePath, "rb");
		$output = fopen($destinationPath, "wb");
		if ($input === false || $output === false) {
			if (is_resource($input)) {
				fclose($input);
			}
			if (is_resource($output)) {
				fclose($output);
			}
			throw new LogicException("Could not open resource pack files for encryption");
		}

		$iv = substr($key, 0, 16);
		try {
			while (!feof($input)) {
				$plain = fread($input, 1048576);
				if ($plain === false) {
					throw new LogicException("Could not read resource pack while encrypting");
				}
				if ($plain === "") {
					continue;
				}
				$encrypted = openssl_encrypt($plain, "aes-256-cfb8", $key, OPENSSL_RAW_DATA, $iv);
				if ($encrypted === false) {
					throw new LogicException("OpenSSL failed to encrypt a resource pack");
				}
				$this->writeAll($output, $encrypted);
				$iv = substr($encrypted, -16);
			}
		} finally {
			fclose($input);
			fclose($output);
		}
	}

	private function writeAll($stream, string $data): void
	{
		$length = strlen($data);
		$written = 0;
		while ($written < $length) {
			$count = fwrite($stream, substr($data, $written));
			if ($count === false || $count === 0) {
				throw new LogicException("Could not write encrypted resource pack");
			}
			$written += $count;
		}
	}
}
