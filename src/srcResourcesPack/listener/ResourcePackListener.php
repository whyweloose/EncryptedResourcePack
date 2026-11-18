<?php

declare(strict_types=1);

namespace srcResourcesPack\listener;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerResourcePackOfferEvent;
use srcResourcesPack\service\ResourcePackService;

final class ResourcePackListener implements Listener
{
	public function __construct(private ResourcePackService $service)
	{
	}

	public function onResourcePackOffer(PlayerResourcePackOfferEvent $event): void
	{
		$player = $event->getPlayer();
		if ($player->getProtocolVersion() < $player->getServer()->getBedrockResourcePacksProtocol()) {
			return;
		}

		$event->setResourcePacks($this->service->getResourcePacks(), $this->service->getEncryptionKeys());
		$event->setMustAccept((bool) $this->service->getPlugin()->getConfig()->get("force_resources", true));
	}
}
