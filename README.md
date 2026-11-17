# EncryptedResourcePack

Плагин PocketMine-MP / Submarine, который отдаёт игрокам ресурс-паки из своей папки данных в зашифрованном виде. Переключайте язык, раскрывая нужный раздел.

<details open>
<summary>Русский</summary>

## Возможности

- Загружает `.zip` и `.mcpack` из папки `packs/` плагина.
- Шифрует архивы алгоритмом AES-256-CFB8 и передаёт ключ через API ресурс-паков Bedrock.
- Сохраняет подготовленный шифротекст в `encrypted/` и пересоздаёт его при изменении архива или ключа.
- Позволяет задать порядок паков и требовать их принятия от игрока.

## Требования

- PocketMine-MP / Submarine с API `3.28.0`.
- Версия ядра с поддержкой `PlayerResourcePackOfferEvent` в [Player.php](../../Submarine/src/pocketmine/Player.php). Изменение ядра должно сохранять список паков и ключи события при выдаче метаданных, чанков и стека. Установка только плагина на стандартное ядро недостаточна.
- PHP-расширение OpenSSL.

## Установка

1. Соберите плагин согласно инструкции проекта и поместите его PHAR в `plugins/`.
2. Установите и запустите совместимое ядро Submarine. При первом запуске плагин создаст конфигурацию и каталоги `packs/` и `encrypted/` в своей папке данных.
3. Скопируйте исходные ресурс-паки (`.zip` или `.mcpack`) в `plugins/srcResourcesPack/packs/`.
4. Отредактируйте `plugins/srcResourcesPack/config.yml`: перечислите имена файлов в `resource_stack` и задайте `force_resources`.
5. Перезапустите сервер. При первом чтении каждого пака рядом с архивом появится файл `<имя_архива>.key` с 32 случайными байтами.

Фактический путь папки данных зависит от `plugins.legacy-data-dir` в конфигурации ядра. Если этот параметр включён, используется `plugins/srcResourcesPack/`; если выключен — `plugin_data/srcResourcesPack/`.

Паки должны быть корректными ZIP-архивами с `manifest.json` в корне и уникальными UUID. Плагин читает манифест из исходного архива, поэтому его нельзя удалять после запуска. В `resource_stack` указываются точные имена файлов с учётом регистра. Перечисленные паки идут первыми в указанном порядке; остальные найденные архивы добавляются после них по алфавиту.

## Конфигурация

```yaml
resource_stack:
  - hypegoui.zip
  - HypeGOAssets.zip
force_resources: true
```

- `resource_stack` — желаемый порядок файлов. Отсутствующие в каталоге имена пропускаются; неуказанные архивы всё равно загружаются после перечисленных.
- `force_resources: true` — игрок должен принять пакеты, чтобы подключиться. Значение `false` делает принятие необязательным.

После добавления или замены архива перезапустите сервер: плагины загружают паки при включении. Не меняйте ключ без необходимости: он влияет на шифрование, и кэш будет создан заново.

## Ключи и безопасность

Файл `.key` — бинарный, ровно 32 байта, не текст и не Base64. Плагин создаёт его автоматически. При восстановлении резервной копии можно вернуть собственный ключ, если он содержит ровно 32 байта; сохранение прежнего ключа позволяет использовать тот же ключ для пака. Храните исходные архивы и ключи в резервной копии.

Шифрование скрывает содержимое скачиваемого файла от простого просмотра. Поскольку ключ передаётся клиенту, который расшифровывает пак, владелец клиента может извлечь ключ и исходные данные. Это не DRM и не защита от целевого извлечения ресурса.

## Диагностика

- В логе `Loaded N encrypted resource pack(s) from plugin data` число `N` показывает, сколько паков подготовлено.
- Проверьте, что архив находится непосредственно в `packs/`, а расширение — `.zip` или `.mcpack`.
- Ошибка `The OpenSSL PHP extension is required` означает, что PHP запущен без расширения OpenSSL.
- Ошибка о ключе означает, что соответствующий `.key` не содержит ровно 32 байта. Удалите ошибочный ключ, чтобы плагин создал новый, либо восстановите корректную резервную копию.
- Ошибка разбора пака обычно означает повреждённый ZIP, отсутствующий `manifest.json` или повтор UUID.
- Если клиент не получает список паков, проверьте, что сервер использует ядро с изменённым `Player.php` и что в логе плагина нет ошибок загрузки.

</details>

<details>
<summary>English</summary>

## Features

- Loads `.zip` and `.mcpack` files from the plugin's `packs/` data directory.
- Encrypts archives with AES-256-CFB8 and supplies the key through the Bedrock resource-pack API.
- Caches encrypted files in `encrypted/` and rebuilds them when the source archive or key changes.
- Lets you set pack order and require players to accept the packs.

## Requirements

- PocketMine-MP / Submarine API `3.28.0`.
- A core build with `PlayerResourcePackOfferEvent` support in [Player.php](../../Submarine/src/pocketmine/Player.php). The core change must preserve the event's packs and keys when serving pack metadata, chunks, and the stack. Installing the plugin on an unmodified core is not sufficient.
- The PHP OpenSSL extension.

## Installation

1. Build the plugin using the project instructions and place its PHAR in `plugins/`.
2. Install and start a compatible Submarine core. On first startup, the plugin creates its config and the `packs/` and `encrypted/` directories in its data folder.
3. Copy source resource packs (`.zip` or `.mcpack`) into `plugins/srcResourcesPack/packs/`.
4. Edit `plugins/srcResourcesPack/config.yml`: list filenames in `resource_stack` and set `force_resources`.
5. Restart the server. When a pack is first read, a neighboring `<archive-name>.key` file containing 32 random bytes is created.

The actual data-folder path depends on `plugins.legacy-data-dir` in the core configuration. When enabled, the path is `plugins/srcResourcesPack/`; when disabled, it is `plugin_data/srcResourcesPack/`.

Packs must be valid ZIP archives with `manifest.json` at the root and unique UUIDs. The plugin reads the manifest from the source archive, so keep that archive on the server after startup. Use exact filenames, including letter case, in `resource_stack`. Listed packs come first in the specified order; other discovered archives are appended alphabetically.

## Configuration

```yaml
resource_stack:
  - hypegoui.zip
  - HypeGOAssets.zip
force_resources: true
```

- `resource_stack` sets the preferred file order. Names not found in the directory are skipped; other archives are still loaded after the listed ones.
- `force_resources: true` requires players to accept the packs to join. Set it to `false` to make acceptance optional.

Restart the server after adding or replacing an archive; packs are loaded when the plugin enables. Avoid changing a key unless needed: the encrypted cache will be rebuilt.

## Keys and security

The `.key` file is binary, exactly 32 bytes, and is not text or Base64. The plugin creates it automatically. To restore a backup, you may provide your own key file if it contains exactly 32 bytes; keeping the existing key preserves the pack's key. Back up both source archives and keys.

Encryption prevents casual inspection of the downloaded file. The key is sent to the client so it can decrypt the pack, which means a client owner can extract the key and source data. This is not DRM and does not prevent deliberate asset extraction.

## Troubleshooting

- The log line `Loaded N encrypted resource pack(s) from plugin data` reports how many packs were prepared.
- Make sure each archive is directly inside `packs/` and has a `.zip` or `.mcpack` extension.
- `The OpenSSL PHP extension is required` means PHP is running without OpenSSL enabled.
- A key error means the matching `.key` file is not exactly 32 bytes. Remove the invalid key to let the plugin generate another, or restore a valid backup.
- Pack parsing errors usually indicate a corrupt ZIP, a missing `manifest.json`, or a duplicate UUID.
- If clients do not receive the pack list, confirm that the server is running a core with the `Player.php` change and check the plugin log for pack-loading errors.

</details>
