<?php

declare(strict_types=1);

namespace srcResourcesPack\resource;

use LogicException;
use pocketmine\resourcepacks\ResourcePack;
use pocketmine\resourcepacks\ZippedResourcePack;
use function filesize;
use function fread;
use function fseek;
use function fclose;
use function fopen;
use function hash_file;
use function is_resource;
use function min;
use function strlen;

final class EncryptedResourcePack implements ResourcePack
{
	private $file;
	private string $encryptionKey;
	private int $packSize;
	private string $sha256;

	public function __construct(
		private ZippedResourcePack $metadata,
		private string $encryptedPath,
		string $encryptionKey
	) {
		$this->encryptionKey = $encryptionKey;
		$this->file = fopen($encryptedPath, "rb");
		if ($this->file === false) {
			throw new LogicException("Could not open encrypted resource pack: " . $encryptedPath);
		}
		$this->packSize = (int) filesize($encryptedPath);
		$sha256 = hash_file("sha256", $encryptedPath, true);
		if ($sha256 === false) {
			throw new LogicException("Could not hash encrypted resource pack: " . $encryptedPath);
		}
		$this->sha256 = $sha256;
	}

	public function __destruct()
	{
		if (is_resource($this->file)) {
			fclose($this->file);
		}
	}

	public function getPath(): string
	{
		return $this->encryptedPath;
	}

	public function getPackName(): string
	{
		return $this->metadata->getPackName();
	}

	public function getPackId(): string
	{
		return $this->metadata->getPackId();
	}

	public function getPackSize(): int
	{
		return $this->packSize;
	}

	public function getPackVersion(): string
	{
		return $this->metadata->getPackVersion();
	}

	public function getSha256(): string
	{
		return $this->sha256;
	}

	public function getPackChunk(int $start, int $length, bool $useCache = true): string
	{
		if ($start < 0 || $start >= $this->packSize || $length <= 0 || fseek($this->file, $start) !== 0) {
			throw new \InvalidArgumentException("Requested a resource pack chunk with an invalid offset");
		}
		$expectedLength = min($length, $this->packSize - $start);
		$chunk = "";
		while (strlen($chunk) < $expectedLength) {
			$part = fread($this->file, $expectedLength - strlen($chunk));
			if ($part === false || $part === "") {
				throw new LogicException("Could not read the complete encrypted resource pack chunk");
			}
			$chunk .= $part;
		}
		return $chunk;
	}

	public function getEncryptionKey(): ?string
	{
		return $this->encryptionKey;
	}

	public function setEncryptionKey(string $key): void
	{
		$this->encryptionKey = $key;
	}
}
