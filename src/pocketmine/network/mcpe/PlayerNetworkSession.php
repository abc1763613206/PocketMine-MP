<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

declare(strict_types=1);

namespace pocketmine\network\mcpe;

use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\handler\LoginNetworkHandler;
use pocketmine\network\mcpe\handler\NetworkHandler;
use pocketmine\network\mcpe\handler\ResourcePacksNetworkHandler;
use pocketmine\network\mcpe\handler\SimpleNetworkHandler;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\DisconnectPacket;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\PlayStatusPacket;
use pocketmine\network\NetworkInterface;
use pocketmine\Player;
use pocketmine\PlayerParameters;
use pocketmine\Server;
use pocketmine\timings\Timings;

abstract class PlayerNetworkSession{

	/** @var string */
	protected $ip;
	/** @var int */
	protected $port;
	/** @var int */
	protected $lastPingMeasure = 1;

	/** @var float */
	protected $creationTime;

	/** @var Server */
	protected $server;
	/** @var Player */
	protected $player;
	/** @var NetworkInterface */
	protected $interface;
	/** @var PlayerParameters */
	protected $loginData;

	/** @var bool */
	protected $connected = true;

	/** @var bool */
	protected $loggedIn = false;

	/** @var PacketBuffer */
	protected $batchBuffer;

	/** @var \SplQueue|CompressedPacketBuffer[] */
	protected $batchQueue;

	/** @var NetworkHandler */
	protected $handler;

	public function __construct(Server $server, NetworkInterface $interface, string $ip, int $port){
		$this->server = $server;
		$this->interface = $interface;
		$this->ip = $ip;
		$this->port = $port;
		$this->creationTime = microtime(true);

		$this->batchQueue = new \SplQueue();

		$this->handler = new LoginNetworkHandler($this->server, $this);

		$this->server->getNetwork()->addTrackedSession($this);
	}

	public function getIp() : string{
		return $this->ip;
	}

	public function getPort() : int{
		return $this->port;
	}

	public function getPing() : int{
		return $this->lastPingMeasure;
	}

	/**
	 * @internal Called by the network interface to update session ping measurements.
	 *
	 * @param int $pingMS
	 */
	public function updatePing(int $pingMS) : void{
		$this->lastPingMeasure = $pingMS;
	}

	public function getInterface() : NetworkInterface{
		return $this->interface;
	}

	public function getPlayer() : ?Player{
		return $this->player;
	}

	public function isConnected() : bool{
		return $this->connected;
	}

	public function getLoginData() : ?PlayerParameters{
		return $this->loginData;
	}

	public function setLoginData(PlayerParameters $parameters) : void{
		$this->loginData = $parameters;
	}

	/**
	 * @param int $status
	 */
	protected function sendPlayStatus(int $status) : void{
		$pk = new PlayStatusPacket();
		$pk->status = $status;
		$this->sendDataPacket($pk);
	}

	public function onClientAuthenticated(LoginPacket $packet, ?string $error, bool $signedByMojang) : void{
		if(!$this->connected){
			return;
		}

		if($error !== null){
			$this->serverDisconnect($this->server->getLanguage()->translateString("pocketmine.disconnect.invalidSession", [$error]));
			return;
		}

		$xuid = $this->loginData->getXuid();

		if(!$signedByMojang and $xuid !== ""){
			$this->server->getLogger()->warning($this->loginData->getUsername() . " has an XUID, but their login keychain is not signed by Mojang");
			$this->loginData->setXuid("");
		}

		if($xuid === ""){
			if($signedByMojang){
				$this->server->getLogger()->error($this->loginData->getUsername() . " should have an XUID, but none found");
			}

			if($this->server->requiresAuthentication()){
				$this->serverDisconnect("disconnectionScreen.notAuthenticated");

				return;
			}

			$this->server->getLogger()->debug($this->loginData->getUsername() . " is NOT logged into to Xbox Live");
		}else{
			$this->server->getLogger()->debug($this->loginData->getUsername() . " is logged into Xbox Live");
		}

		//TODO: encryption

		//TODO: fix this - how do we track logins prior to creating the player?
		/*foreach($this->server->getLoggedInPlayers() as $p){
			if($p !== $this and ($p->iusername === $this->iusername or $this->getUniqueId()->equals($p->getUniqueId()))){
				if(!$p->kick("logged in from another location")){
					$this->close($this->getLeaveMessage(), "Logged in from another location");

					return;
				}
			}
		}*/

		$this->loggedIn = true;
		//$this->server->onPlayerLogin($this);

		$this->sendPlayStatus(PlayStatusPacket::LOGIN_SUCCESS);

		$this->handler = new ResourcePacksNetworkHandler($this->server, $this);
		$this->handler->sendResourcePacksInfo();
	}

	public function startSpawnSequence() : void{
		//TODO: this is where we should be actually creating the player
		$this->player = $this->server->createPlayer($this, $this->loginData);
		$this->handler = new SimpleNetworkHandler($this->player);
	}

	public function onSpawn() : void{
		//TODO: use a void network handler pre-spawn (it should be replaced with a regular game handler here)
		$this->sendPlayStatus(PlayStatusPacket::PLAYER_SPAWN);
	}


	protected function handleBatch(string $buffer) : void{
		//TODO: this needs to be decrypted before decompression if encryption is enabled

		$batch = PacketBuffer::decompress($buffer);
		foreach($batch->getPackets() as $str){
			$pk = PacketPool::getPacket($str);
			$this->handleDataPacket($pk);
		}
	}

	protected function handleDataPacket(DataPacket $packet){
		$timings = Timings::getReceiveDataPacketTimings($packet);
		$timings->startTiming();

		$packet->decode();
		if(!$packet->feof() and !$packet->mayHaveUnreadBytes()){
			$remains = substr($packet->buffer, $packet->offset);
			$this->server->getLogger()->debug("Still " . strlen($remains) . " bytes unread in " . $packet->getName() . ": 0x" . bin2hex($remains));
		}

		$this->server->getPluginManager()->callEvent($ev = new DataPacketReceiveEvent($this, $packet));

		if(!$ev->isCancelled() and !$packet->handle($this->handler)){
			$this->server->getLogger()->debug("Unhandled " . $packet->getName() . " received from " . $this->getIp() . " " . $this->getPort() . ": 0x" . bin2hex($packet->buffer));
		}

		$timings->stopTiming();
	}

	/**
	 * @param DataPacket $packet
	 * @param bool       $immediateFlush
	 * @param bool       $fireEvent
	 *
	 * @return bool
	 */
	public function sendDataPacket(DataPacket $packet, bool $immediateFlush = false, bool $fireEvent = true) : bool{
		//Basic safety restriction. TODO: improve this
		if(!$this->loggedIn and !$packet->canBeSentBeforeLogin()){
			throw new \InvalidArgumentException("Attempted to send " . get_class($packet) . " to " . $this->getIp() . " " . $this->getPort() . " too early");
		}

		$timings = Timings::getSendDataPacketTimings($packet);
		$timings->startTiming();
		try{
			if($fireEvent){
				$this->server->getPluginManager()->callEvent($ev = new DataPacketSendEvent($this, $packet));
				if($ev->isCancelled()){
					return false;
				}
			}

			$this->addToBatchBuffer($packet);
			if($immediateFlush){
				$this->flushBatchBuffer($immediateFlush);
			}

			return true;
		}finally{
			$timings->stopTiming();
		}
	}

	/**
	 * Adds a packet to the session list to be sent in a batch at the next available opportunity.
	 *
	 * @param DataPacket $packet
	 */
	private function addToBatchBuffer(DataPacket $packet) : void{
		if($this->batchBuffer === null){
			$this->batchBuffer = new PacketBuffer();
		}

		$this->batchBuffer->addPacket($packet);
	}

	/**
	 * Flushes pending buffered packets in a single batch to the network.
	 *
	 * @param bool $immediateFlush
	 */
	private function flushBatchBuffer(bool $immediateFlush = false) : void{
		if($this->batchBuffer !== null){
			//this might sync-send and call back to this again, so make sure we don't double-flush
			$buf = $this->batchBuffer;
			$this->batchBuffer = null;

			$this->server->prepareBatch([$this], $buf, $immediateFlush, $immediateFlush);
		}
	}

	public function serverDisconnect(string $reason = "", bool $mcpeDisconnect = true) : void{
		if($this->connected){
			$this->connected = false;
			$this->server->getNetwork()->removeTrackedSession($this);

			if($this->player !== null){
				$this->player->close("", $reason, $mcpeDisconnect);
			}

			if($mcpeDisconnect){
				$pk = new DisconnectPacket();
				$pk->message = $reason;
				$pk->hideDisconnectionScreen = $reason === "";
				$this->sendDataPacket($pk, true);
			}

			$this->disconnectFromInterface($reason);

			$this->player = null;
			$this->interface = null;
			$this->handler = null;
		}
	}

	abstract protected function disconnectFromInterface(string $reason) : void;

	/**
	 * @internal Called by the network interface when a player disconnects of their own accord.
	 *
	 * @param string $reason
	 */
	public function onClientDisconnect(string $reason) : void{
		if($this->connected){
			$this->connected = false;

			if($this->player !== null){
				$this->player->close($this->player->getLeaveMessage(), $reason);
				$this->player = null;
			}

			$this->interface = null;
			$this->handler = null;
		}
	}


	public function notifyPendingBatch(CompressedPacketBuffer $buffer) : void{
		$this->flushBatchBuffer();
		$this->batchQueue->enqueue($buffer);
	}

	public function sendPreparedBatch(CompressedPacketBuffer $buffer, bool $immediateFlush = false) : void{
		$this->flushBatchBuffer($immediateFlush);
		$this->batchQueue->enqueue($buffer);
		$this->flushBatchQueue($immediateFlush);
	}

	public function flushBatchQueue(bool $immediateFlush = false) : void{
		while(!$this->batchQueue->isEmpty()){
			/** @var CompressedPacketBuffer $nextBatch */
			$nextBatch = $this->batchQueue->bottom();
			if($nextBatch->isReady()){
				//this gets modified by the async task preparing it
				$this->batchQueue->dequeue();

				//TODO: encryption

				$this->sendBatch($nextBatch->getBuffer(), $immediateFlush);
			}else{
				//we're still waiting for this one being async-prepared
				break;
			}
		}
	}

	abstract protected function sendBatch(string $buffer, bool $immediateFlush) : void;

	/**
	 * @param float $microtime
	 */
	public function tick(float $microtime) : void{
		if(!$this->loggedIn and $this->creationTime + 10 < $microtime){
			$this->serverDisconnect("Login timeout");
			return;
		}
		if($this->batchBuffer !== null){
			$this->flushBatchBuffer();
		}
	}

	public function getHandler() : NetworkHandler{
		return $this->handler;
	}

	public function setHandler(NetworkHandler $handler) : void{
		$this->handler = $handler;
	}
}
