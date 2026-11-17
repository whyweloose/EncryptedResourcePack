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
use function function_exists;
use function hash;
use function hash_file;
use function is_dir;
use function is_file;
use function is_string;
use function in_array;
use function json_encode;
use function mkdir;
use function openssl_encrypt;
use function random_bytes;
use function rename;
use function scandir;
use function str_ends_with;
use function strlen;
use function strtolower;
use function substr;
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
		$cacheKey = hash("sha256", "bedrock-encrypted-zip-v1\0" . $sourceHash . "\0" . $key);
		$base = hash("sha256", strtolower($packId));
		$encryptedPath = $this->encryptedDirectory . $base . ".pack";
		$metadataPath = $encryptedPath . ".meta";
		if (is_file($encryptedPath) && is_file($metadataPath) && file_get_contents($metadataPath) === $cacheKey) {
			return $encryptedPath;
		}

		$tempPath = $encryptedPath . ".tmp-" . bin2hex(random_bytes(6));
		try {
			$this->encryptPackArchive($sourcePath, $tempPath, $packId, $key);
		} catch (\Throwable $error) {
			@unlink($tempPath);
			throw $error;
		}
		if (!rename($tempPath, $encryptedPath)) {
			@unlink($tempPath);
			throw new LogicException("Could not replace encrypted resource pack: " . $encryptedPath);
		}
		if (file_put_contents($metadataPath, $cacheKey, LOCK_EX) === false) {
			throw new LogicException("Could not write encrypted resource pack cache metadata");
		}
		return $encryptedPath;
	}

	private function encryptPackArchive(string $sourcePath, string $destinationPath, string $packId, string $key): void
	{
		$input = new \ZipArchive();
		$inputResult = $input->open($sourcePath);
		if ($inputResult !== true) {
			throw new LogicException("Could not open source resource pack ZIP (error " . $inputResult . ")");
		}
		$output = new \ZipArchive();
		$outputResult = $output->open($destinationPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
		if ($outputResult !== true) {
			$input->close();
			throw new LogicException("Could not create encrypted resource pack ZIP (error " . $outputResult . ")");
		}

		$contentEntries = [];
		$excludedFiles = ["manifest.json", "pack_icon.png", "bug_pack_icon.png"];
		$outputClosed = false;
		try {
			for ($index = 0; $index < $input->numFiles; ++$index) {
				$name = $input->getNameIndex($index);
				if ($name === false || $name === "contents.json") {
					continue;
				}
				if (str_ends_with($name, "/")) {
					if (!$output->addEmptyDir($name)) {
						throw new LogicException("Could not add resource pack directory: " . $name);
					}
					continue;
				}
				$plain = $input->getFromIndex($index);
				if ($plain === false) {
					throw new LogicException("Could not read resource pack entry: " . $name);
				}
				if (in_array($name, $excludedFiles, true)) {
					$entryKey = null;
					$contents = $plain;
				} else {
					$entryKey = bin2hex(random_bytes(16));
					$iv = substr($entryKey, 0, 16);
					$contents = openssl_encrypt($plain, "aes-256-cfb8", $entryKey, OPENSSL_RAW_DATA, $iv);
					if ($contents === false) {
						throw new LogicException("OpenSSL failed to encrypt resource pack entry: " . $name);
					}
				}
				if (!$output->addFromString($name, $contents)) {
					throw new LogicException("Could not write resource pack entry: " . $name);
				}
				$contentEntries[] = ["path" => $name, "key" => $entryKey];
			}

			$contentJson = json_encode(["content" => $contentEntries], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
			$header = str_repeat("\x00", 16);
			$header = substr($header, 0, 4) . "\xFC\xB9\xCF\x9B" . substr($header, 8);
			$header = substr($header, 0, 16) . chr(strlen($packId)) . $packId;
			$header = str_pad($header, 0x100, "\x00");
			$contentsIv = substr($key, 0, 16);
			$encryptedContent = openssl_encrypt($contentJson, "aes-256-cfb8", $key, OPENSSL_RAW_DATA, $contentsIv);
			if ($encryptedContent === false || !$output->addFromString("contents.json", $header . $encryptedContent)) {
				throw new LogicException("Could not write encrypted resource pack contents.json");
			}
			if (!$output->close()) {
				throw new LogicException("Could not finalize encrypted resource pack ZIP");
			}
			$outputClosed = true;
		} finally {
			if (!$outputClosed) {
				$output->close();
			}
			$input->close();
		}
	}
}
