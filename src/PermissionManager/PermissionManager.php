<?php

/*
 *       _       _        ___ _____ _  ___
 *   __ _| |_   _(_)_ __  / _ \___ // |/ _ \
 * / _` | \ \ / / | '_ \| | | ||_ \| | (_) |
 * | (_| | |\ V /| | | | | |_| |__) | |\__, |
 *  \__,_|_| \_/ |_|_| |_|\___/____/|_|  /_/
 *
 * Copyright (C) 2020 alvin0319
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace PermissionManager;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerCommandPreprocessEvent;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\player\PlayerGameModeChangeEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\item\Item;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\Config;

class PermissionManager extends PluginBase implements Listener
{

	public static $prefix = "§b§l[권한] §r§7";

	/** @var Permission[] */
	protected $permission = [];

	protected $playerPermission = [];

	protected $invData = [];

	/** @var Config */
	protected $config;

	public function onEnable()
	{
		$this->saveResource("settings.yml");
		$this->config = new Config($this->getDataFolder() . "settings.yml", Config::YAML);
		$this->getServer()->getPluginManager()->registerEvents($this, $this);

		$permissionData = new Config($this->getDataFolder() . "Permissions.json", Config::JSON);
		foreach ($permissionData->getAll() as $permissionDatum => $datum) {
			$perm = Permission::jsonDeserialize($datum);
			$this->permission[$perm->getName()] = $perm;
		}

		$playerData = new Config($this->getDataFolder() . "PlayerData.json", Config::JSON);

		foreach ($playerData->getAll() as $key => $data) {
			$this->playerPermission[$key] = [
				"permission" => $this->getPermission($data["permission"]),
				"player" => $data["player"]
			];
		}

		$invData = new Config($this->getDataFolder() . "InventoryData.json", Config::JSON);

		$this->invData = $invData->getAll();

		$this->getScheduler()->scheduleTask(new ClosureTask(function (int $unused): void {
			foreach ($this->getServer()->getCommandMap()->getCommands() as $command) {
				$command->setPermissionMessage(PermissionManager::$prefix . $this->getConfig()->getNested("permission-message", "당신은 이 명령어를 사용할 권한이 없습니다. 만약 자신이 관리자임을 믿는다면, /su <관리자키> 로 관리자임을 입증해주세요."));
			}
		}));

		$command = $this->getServer()->getCommandMap()->getCommand("op");
		//$command->setLabel($command->getLabel() . "__disabled"); It Doesn't need
		$this->getServer()->getCommandMap()->unregister($command);
		$command = $this->getServer()->getCommandMap()->getCommand("deop");
		//$command->setLabel($command->getLabel() . "__disabled"); Too
		$this->getServer()->getCommandMap()->unregister($command);
	}

	public function onDisable()
	{
		$permissionData = new Config($this->getDataFolder() . "Permissions.json", Config::JSON);
		foreach ($this->permission as $permission) {
			$permissionData->setNested($permission->getName(), $permission->jsonSerialize());
		}
		$permissionData->save();

		$playerData = new Config($this->getDataFolder() . "PlayerData.json", Config::JSON);

		$playerData->setAll([]);

		foreach ($this->playerPermission as $key => $data) {
			$datum = [
				"permission" => $data["permission"]->getName(),
				"player" => $data["player"]
			];
			$playerData->setNested($key, $datum);
		}
		$playerData->save();

		$invConfig = new Config($this->getDataFolder() . "InventoryData.json", Config::JSON);
		$invConfig->setAll($this->invData);
		$invConfig->save();
	}

	public function getPermission(string $perm): ?Permission
	{
		return $this->permission[$perm] ?? null;
	}

	public function getKey(string $key): ?Permission
	{
		return $this->playerPermission[$key] ["permission"] ?? null;
	}

	public function addPermission(Permission $permission)
	{
		$this->permission[$permission->getName()] = $permission;
	}

	public function addKey(string $key, Permission $permission)
	{
		$this->playerPermission[$key] = [
			"permission" => $permission,
			"player" => ""
		];
	}

	public function getConfig(): Config
	{
		return $this->config;
	}

	public function removeKey(string $key)
	{
		unset($this->playerPermission[$key]);
	}

	public function getUsedPlayer(string $key): string
	{
		return $this->playerPermission[$key] ["player"];
	}

	public function setUsedPlayer(Player $player, string $key)
	{
		$this->playerPermission[$key] ["player"] = $player->getName();
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool
	{
		if ($sender instanceof Player) {
			if (!isset($args[0])) {
				$sender->sendMessage(PermissionManager::$prefix . "/su <관리자키>");
				return true;
			}

			$key = $args[0];
			if (!($perm = $this->getKey($key)) instanceof Permission) {
				$sender->sendMessage(PermissionManager::$prefix . "해당 키는 등록되어있지 않습니다.");
				$this->getLogger()->emergency($sender->getName() . ": 잘못된 관리자키 입력, \"$key\"");
				return true;
			}
			$usedPlayer = $this->getUsedPlayer($key);
			if ($usedPlayer === "") {
				$this->setUsedPlayer($sender, $key);
				$sender->sendMessage(PermissionManager::$prefix . "권한을 지급받으셨습니다.");
				foreach ($perm->getPermissions() as $permission) {
					if ($permission === "op") {
						$sender->setOp(true);
						$sender->setGamemode(Player::CREATIVE);
						break;
					} else {
						$sender->addAttachment($this, $permission, true);
					}
				}
			} else {
				if ($sender->getName() === $usedPlayer) {
					$sender->sendMessage(PermissionManager::$prefix . "권한을 지급받으셨습니다.");
					foreach ($perm->getPermissions() as $permission) {
						if ($permission === "op") {
							$sender->setOp(true);
							$sender->setGamemode(Player::CREATIVE);
							break;
						} else {
							$sender->addAttachment($this, $permission, true);
						}
					}
				} else {
					$sender->close("", "당신은 이 키의 사용자가 아닙니다.");
					$this->getLogger()->emergency($sender->getName() . ": \"$key\" 관리자키의 주인이 아님.");
				}
			}
		} else {
			if (!isset($args[0])) {
				foreach ([
							 ["/su padd <권한이름>", "권한을 추가합니다."],
							 ["/su permadd <권한이름> <하위권한|string>", "권한에 하위권한을 부여합니다."],
							 ["/su keyadd <관리자키> <권한이름>", "사용하지 않은 관리자키를 추가합니다."],
							 ["/su keyremove <관리자키>", "관리자키를 제거합니다."],
							 ["/su list", "관리자키의 목록을 봅니다."]
						 ] as $usage) {
					$sender->sendMessage(PermissionManager::$prefix . $usage[0] . " - " . $usage[1]);
				}
				return true;
			}
			switch ($args[0]) {
				case "padd":
					if (!isset($args[1])) {
						$sender->sendMessage(PermissionManager::$prefix . "권한이름을 입력해주세요.");
						break;
					}
					if ($this->getPermission($args[1]) instanceof Permission) {
						$sender->sendMessage(PermissionManager::$prefix . "해당 이름의 권한이 존재합니다.");
						break;
					}
					$perm = new Permission($args[1]);
					$this->addPermission($perm);

					$sender->sendMessage(PermissionManager::$prefix . "추가하였습니다.");
					break;
				case "permadd":
					if (!isset($args[1])) {
						$sender->sendMessage(PermissionManager::$prefix . "권한이름을 입력해주세요.");
						break;
					}
					if (!($perm = $this->getPermission($args[1])) instanceof Permission) {
						$sender->sendMessage(PermissionManager::$prefix . "해당 이름의 권한이 존재하지 않습니다.");
						break;
					}
					if (!isset($args[2])) {
						$sender->sendMessage(PermissionManager::$prefix . "추가할 권한을 입력해주세요. ex) pocketmine.command.gamemode");
						break;
					}

					$perm->addPermission($args[2]);
					$sender->sendMessage(PermissionManager::$prefix . "권한을 추가하였습니다.");
					break;
				case "keyadd":
					if (!isset($args[1])) {
						$sender->sendMessage(PermissionManager::$prefix . "키를 입력해주세요.");
						break;
					}
					if ($this->getKey($args[1]) instanceof Permission) {
						$sender->sendMessage(PermissionManager::$prefix . "해당 키가 이미 존재합니다.");
						break;
					}
					if (!isset($args[2])) {
						$sender->sendMessage(PermissionManager::$prefix . "권한이름을 입력해주세요.");
						break;
					}
					if (!($perm = $this->getPermission($args[2])) instanceof Permission) {
						$sender->sendMessage(PermissionManager::$prefix . "해당 이름의 권한이 존재하지 않습니다.");
						break;
					}
					$this->addKey($args[1], $perm);
					$sender->sendMessage(PermissionManager::$prefix . "추가하였습니다.");
					break;
				case "keyremove":
					if (!isset($args[1])) {
						$sender->sendMessage(PermissionManager::$prefix . "키를 입력해주세요.");
						break;
					}
					if (!($key = $this->getKey($args[1])) instanceof Permission) {
						$sender->sendMessage(PermissionManager::$prefix . "해당 키가 이미 존재하지 않습니다.");
						break;
					}
					$this->removeKey($args[1]);
					$sender->sendMessage(PermissionManager::$prefix . "제거되었습니다.");
					break;
				case "list":
					$sender->sendMessage(PermissionManager::$prefix . "권한 목록: " . implode(", ", array_map(function (string $key, array $data): string {
							return $key . ": " . $data["permission"]->getName() . "(사용자: " . ($data["player"] === "" ? "사용되지 않음" : $data["player"]) . ")";
						}, array_keys($this->playerPermission), $this->playerPermission)));
					break;
				default:
					foreach ([
								 ["/su padd <권한이름>", "권한을 추가합니다."],
								 ["/su permadd <권한이름> <하위권한|string>", "권한에 하위권한을 부여합니다."],
								 ["/su keyadd <관리자키> <권한이름>", "사용하지 않은 관리자키를 추가합니다."],
								 ["/su keyremove <관리자키>", "관리자키를 제거합니다."],
								 ["/su list", "관리자키의 목록을 봅니다."]
							 ] as $usage) {
						$sender->sendMessage(PermissionManager::$prefix . $usage[0] . " - " . $usage[1]);
					}
			}
		}
		return true;
	}

	public function onQuit(PlayerQuitEvent $event)
	{
		if ($event->getPlayer()->isOp()) {
			$event->getPlayer()->setOp(false);
		}
		$event->getPlayer()->setGamemode(Player::SURVIVAL);
	}

	public function onJoin(PlayerJoinEvent $event)
	{
		if ($event->getPlayer()->isOp()) {
			$event->getPlayer()->setOp(false);
		}

		if ($event->getPlayer()->isCreative()) {
			$event->getPlayer()->setGamemode(Player::SURVIVAL);
		}
	}

	public function onPlayerCommand(PlayerCommandPreprocessEvent $event)
	{
		$player = $event->getPlayer();

		if (substr($event->getMessage(), 0, -1) === "/") {
			if (!$this->getConfig()->getNested("allow-sell-command", false)) {
				if (strpos($event->getMessage(), '/판매') !== false) {
					if ($player->isCreative()) {
						$event->setCancelled();
						$player->sendMessage(PermissionManager::$prefix . "크리에이티브 모드에서는 \"/판매\" 명령어를 사용할 수 없습니다.");
					}
				}
			}
		}

	}

	public function onPlayerItemDrop(PlayerDropItemEvent $event)
	{
		$player = $event->getPlayer();

		if (!$this->getConfig()->getNested("allow-drop-item", false)) {
			if ($player->isCreative()) {
				$event->setCancelled();
				$player->sendMessage(PermissionManager::$prefix . "크리에이티브 모드에서는 아이템을 드롭할 수 없습니다.");
			}
		}
	}

	public function onGameModeChange(PlayerGameModeChangeEvent $event)
	{
		$player = $event->getPlayer();

		if ($this->getConfig()->getNested("change-inventory", true)) {
			if ($event->getNewGamemode() === Player::CREATIVE) {
				if (isset($this->invData[$player->getName()])) {
					if (isset($this->invData[$player->getName()] ["survival"])) {
						$arr = [];
						foreach ($player->getInventory()->getContents(true) as $index => $item) {
							$arr[$index] = $item->jsonSerialize();
						}
						$this->invData[$player->getName()] ["creative"] = $arr;
						$player->getInventory()->clearAll();
						foreach ($this->invData[$player->getName()] ["survival"] as $slot => $itemData) {
							$item = Item::jsonDeserialize($itemData);
							$player->getInventory()->setItem($slot, $item);
						}
					} else {
						$this->invData[$player->getName()] ["survival"] = [];
					}
				} else {
					$this->invData[$player->getName()] = [
						"creative" => [],
						"survival" => []
					];
				}
			} else {
				if (isset($this->invData[$player->getName()])) {
					if (isset($this->invData[$player->getName()] ["creative"])) {
						$arr = [];
						foreach ($player->getInventory()->getContents(true) as $index => $item) {
							$arr[$index] = $item->jsonSerialize();
						}
						$this->invData[$player->getName()] ["survival"] = $arr;
						$player->getInventory()->clearAll();
						foreach ($this->invData[$player->getName()] ["creative"] as $slot => $itemData) {
							$item = Item::jsonDeserialize($itemData);
							$player->getInventory()->setItem($slot, $item);
						}
					} else {
						$this->invData[$player->getName()] ["creative"] = [];
					}
				} else {
					$this->invData[$player->getName()] = [
						"creative" => [],
						"survival" => []
					];
				}
			}
		}
	}
}