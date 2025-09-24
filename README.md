# change_notification — Roundcube plugin

**License:** GPLv3  
**Composer:** `texxasrulez/change_notification` (type: `roundcube-plugin`)

Upload a per-user audio file (mp3/ogg/flac/wav/m4a) and use it as the
"new message" notification sound when the Roundcube preference
**Settings → Mailbox View → Play the sound on new message** is enabled.

## Install (composer)
```
composer require texxasrulez/change_notification
```

Manual install: copy `change_notification` into your Roundcube `plugins/` dir
Then enable in `config/config.inc.php`:
```
$config['plugins'][] = 'change_notification';
```

Optionally copy `plugins/change_notification/config.inc.php.dist` to
`plugins/change_notification/config.inc.php` and adjust limits/paths.

## How it works
- Adds an upload control under **Settings → Mailbox View** using the
  `preferences_list` hook.
- Stores the uploaded file in `plugins/change_notification/user_sounds/{user_id}/`.
- Saves the relative file path in the Roundcube user prefs as `change_notification_file`.
- Injects a tiny JS that points the notification audio element to the per-user
  file at runtime. This respects the existing Roundcube "play sound" toggle.

## Security & notes
- Files are stored per-user in a non-browsable directory and served only through
  the plugin endpoint `plugin.change_notification.get`, which checks the logged-in user.
- Max size defaults to 2MB; MIME and extension validated.

## Uninstall
- Disable the plugin and remove the directory.
- User prefs key `change_notification_file` can be left or removed.
