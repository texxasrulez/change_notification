<?php
/**
 * change_notification — Roundcube plugin (GPLv3)
 * Plays a per-user custom sound on new mail. All debug logging is now gated by
 * the change_notification.debug config flag.
 */
class change_notification extends rcube_plugin
{
    const PLUGIN_VERSION = '1.1.0';
    const PLUGIN_INFO = array(
        'name' => 'change_notification',
        'vendor' => 'Gene Hawkins',
        'version' => self::PLUGIN_VERSION,
        'license' => 'GPL-3.0',
        'uri' => 'https://github.com/texxasrulez/change_notification',
    );

    public static function info(): array
    {
        return self::PLUGIN_INFO;
    }

    public $task = 'settings|mail';

    private $rc;
    private $storage_dir;
    private $max_bytes;
    private $allowed_ext;
    private $allowed_mime;
    private $debug_enabled = false;

    private function log_debug($msg)
    {
        if ($this->debug_enabled) {
            $msg = is_string($msg) ? $msg : json_encode($msg, JSON_UNESCAPED_UNICODE);
            @rcube::write_log('change_notification', $msg);
        }
    }

    public function init()
    {
        $this->rc = rcube::get_instance();
        $this->load_config();
        $this->add_texts('localization/', true, 'change_notification');
        // Read debug flag EARLY and gate all logs behind it
        $this->debug_enabled = (bool)$this->rc->config->get('change_notification.debug', false);
        $this->log_debug('init start');

        $this->storage_dir = $this->rc->config->get('change_notification.storage_dir', __DIR__ . '/user_sounds');
        $this->storage_dir = rtrim(str_replace('\\\\','/',$this->storage_dir), '/');
        $this->log_debug('storage_dir=' . $this->storage_dir);

        $this->max_bytes   = (int)$this->rc->config->get('change_notification.max_bytes', 3 * 1024 * 1024);

        $this->allowed_ext = $this->rc->config->get('change_notification.allowed_ext', array('mp3','ogg','flac','wav','m4a','aac','opus'));
        $this->allowed_ext = array_map('strtolower', (array)$this->allowed_ext);

        $this->allowed_mime = $this->rc->config->get('change_notification.allowed_mime', array(
            'audio/mpeg','audio/ogg','audio/flac','audio/wav','audio/x-wav','audio/mp4','audio/aac',
        ));

        if (!is_dir($this->storage_dir)) { @mkdir($this->storage_dir, 0750, true); }

        // Settings UI
        $this->add_hook('preferences_list', array($this, 'prefs_list'));
        $this->add_hook('preferences_save', array($this, 'prefs_save'));

        // Endpoints
        $this->register_action('plugin.change_notification.get', array($this, 'serve_audio'));
        $this->register_action('plugin.change_notification.upload', array($this, 'handle_upload'));

        // Client JS (handles intercepting/playing the custom chime)
        $this->include_script('change_notification.js');

		// Client CSS
		$this->include_stylesheet('change_notification.css');

        // Publish per-user URL for client
        if ($url = $this->current_user_sound_url()) {
            $this->rc->output->set_env('change_notification_url', $url);
        }

        // Upload helper env
        $this->rc->output->set_env('change_notification_upload_url', $this->rc->url(array('_task'=>'settings','_action'=>'plugin.change_notification.upload')));
        $this->rc->output->set_env('request_token', $this->rc->get_request_token());
    }

    public function prefs_list($p)
    {
        if ($p['section'] != 'mailbox') return $p;

        $field_name = 'rcmfd_change_notification_file';
        $select_name = '_change_notification_selected';
        $accept = '.' . implode(',.', $this->allowed_ext);
        $input = html::tag('input', array('type'=>'file','name'=>$field_name,'accept'=>$accept));
        $selection_marker = html::tag('input', array(
            'type'  => 'hidden',
            'name'  => '_change_notification_selection_present',
            'value' => '1',
        ));

        $current = $this->get_user_relpath();
        $current_html = $this->build_current_sound_html($current);
        $choices_html = $this->build_sound_choices_html($current, $select_name);

        $upload_url = $this->rc->url(array('_task'=>'settings','_action'=>'plugin.change_notification.upload'));
        $token = $this->rc->get_request_token();
		$onclick = "(function(){var fi=document.querySelector('input[name={$field_name}]');if(!fi||!fi.files||!fi.files[0]){alert('Pick a file first');return false;}var fd=new FormData();fd.append('{$field_name}',fi.files[0]);fd.append('_token','{$token}');var x=new XMLHttpRequest();x.open('POST','{$upload_url}',true);x.setRequestHeader('X-Requested-With','XMLHttpRequest');x.onreadystatechange=function(){if(x.readyState===4){try{var r=JSON.parse(x.responseText);}catch(e){r={};}if(r.ok){setTimeout(function(){window.location.reload();},400);}else{alert('Upload failed');}}};x.send(fd);})();";
        $upload_btn = html::tag('button', array('type'=>'button','id'=>'change-sound-upload-btn','class'=>'button mainaction','onclick'=>$onclick), rcube::Q($this->gettext('uploadsound')));

        $content = $input
            . $selection_marker
            . html::span('hint', rcube::Q($this->gettext('hint')))
            . $choices_html
            . $current_html
            . $upload_btn;

        $p['blocks']['change_notification'] = array(
            'name' => rcube::Q($this->gettext('changesound')),
            'options' => array(
                'change_notification_upload' => array(
                    'title'   => rcube::Q($this->gettext('uploadsound')),
                    'content' => $content,
                ),
            ),
        );

        return $p;
    }

    public function prefs_save($p)
    {
        if ($p['section'] != 'mailbox') return $p;

        $selection_present = rcube_utils::get_input_value('_change_notification_selection_present', rcube_utils::INPUT_POST);
        $selected = rcube_utils::get_input_value('_change_notification_selected', rcube_utils::INPUT_POST);
        $selected = is_string($selected) ? trim($selected) : '';

        if ($selection_present) {
            $p['prefs']['change_notification_file'] = ($selected !== '' && $this->is_valid_user_relpath($selected))
                ? $selected
                : '';
        }

        return $p;
    }

    public function handle_upload()
    {
        $this->require_settings_task();
        $this->check_token();

        $field = 'rcmfd_change_notification_file';
        $file  = isset($_FILES[$field]) ? $_FILES[$field] : null;

        if (!$file || !isset($file['tmp_name']) || $file['tmp_name']==='') {
            return $this->respond_upload(false, 'nofile');
        }
        if ((int)$file['error'] !== UPLOAD_ERR_OK) {
            return $this->respond_upload(false, 'upload');
        }

        $name = (string)$file['name'];
        $tmp  = (string)$file['tmp_name'];
        $size = (int)$file['size'];
        $type = (string)$file['type'];

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $this->allowed_ext, true)) {
            return $this->respond_upload(false, 'ext');
        }
        if ($size > $this->max_bytes) {
            return $this->respond_upload(false, 'size');
        }

        $uid = $this->rc->user->ID;
        $udir = $this->storage_dir . '/' . $uid;
        if (!is_dir($udir)) { @mkdir($udir, 0750, true); }

        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($name));
        $dest = $udir . '/' . $safe;

        if (!@move_uploaded_file($tmp, $dest)) {
            return $this->respond_upload(false, 'move');
        }

        $rel = $uid . '/' . $safe;
        $prefs = $this->rc->user->get_prefs();
        $prefs['change_notification_file'] = $rel;
        $this->rc->user->save_prefs($prefs);

        return $this->respond_upload(true);
    }

    public function serve_audio()
    {
        $this->require_login();

        $rel = rcube_utils::get_input_value('_id', rcube_utils::INPUT_GET);
        $uid = $this->rc->user->ID;
        if (!$rel || !preg_match('/^' . preg_quote($uid, '/') . '\//', (string)$rel)) {
            header($_SERVER['SERVER_PROTOCOL'] . ' 403 Forbidden'); exit;
        }

        $path = rtrim($this->storage_dir, '/\\') . DIRECTORY_SEPARATOR . $rel;
        if (!is_file($path) || !is_readable($path)) {
            header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found'); exit;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $m = array(
            'mp3'=>'audio/mpeg','ogg'=>'audio/ogg','opus'=>'audio/ogg','flac'=>'audio/flac',
            'wav'=>'audio/wav','m4a'=>'audio/mp4','aac'=>'audio/aac',
        );
        $mime = isset($m[$ext]) ? $m[$ext] : 'application/octet-stream';

        $size  = filesize($path);
        $mtime = filemtime($path);
        $etag  = sprintf('W/"%x-%x"', $size, $mtime);

        if ((isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) ||
            (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $mtime)) {
            header('HTTP/1.1 304 Not Modified');
            header('ETag: ' . $etag);
            header('Cache-Control: private, max-age=300');
            exit;
        }

        while (ob_get_level()) { ob_end_clean(); }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $size);
        header('Content-Disposition: inline; filename="' . $this->rawbasename($path) . '"');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        header('ETag: ' . $etag);
        header('Cache-Control: private, max-age=300');

        readfile($path);
        exit;
    }

    // ---- helpers ----

    private function current_user_sound_url()
    {
        $prefs = $this->rc->user ? $this->rc->user->get_prefs() : array();
        $rel = isset($prefs['change_notification_file']) ? $prefs['change_notification_file'] : null;
        if (!$rel) return null;
        // cache-buster to refresh after re-uploads
        return $this->rc->url(array('_action'=>'plugin.change_notification.get','_id'=>$rel,'_'=>time()));
    }

    private function get_user_relpath()
    {
        $prefs = $this->rc->user ? $this->rc->user->get_prefs() : array();
        return isset($prefs['change_notification_file']) ? $prefs['change_notification_file'] : null;
    }

    private function build_current_sound_html($current)
    {
        if (!$current || !$this->is_valid_user_relpath($current)) {
            return '';
        }

        $playurl = html::quote($this->rc->url(array(
            '_action' => 'plugin.change_notification.get',
            '_id'     => $current,
            '_'       => time(),
        )));
        $basename = rcube::Q(basename($current));

        return html::div(null,
            html::span(null, rcube::Q($this->gettext('currentsound') . ': '))
            . $basename
            . ' '
            . html::a(array(
                'href'                             => $playurl,
                'onclick'                          => "new Audio('".$playurl."').play(); return false;",
                'data-change-notification-preview' => $playurl,
            ), rcube::Q($this->gettext('playtest')))
        );
    }

    private function build_sound_choices_html($current, $select_name)
    {
        $sounds = $this->list_user_sounds();
        if (empty($sounds)) {
            return '';
        }

        $items = array();
        foreach ($sounds as $rel) {
            $basename = rcube::Q(basename($rel));
            $checked = ($rel === $current);
            $playurl = html::quote($this->rc->url(array(
                '_action' => 'plugin.change_notification.get',
                '_id'     => $rel,
                '_'       => time(),
            )));

            $checkbox = html::tag('input', array(
                'type'                          => 'checkbox',
                'name'                          => $select_name,
                'value'                         => $rel,
                'checked'                       => $checked ? 'checked' : null,
                'data-change-notification-pick' => 1,
            ));
            $label = html::tag('label', null, $checkbox . ' ' . $basename);
            $preview = html::a(array(
                'href'                             => $playurl,
                'onclick'                          => "new Audio('".$playurl."').play(); return false;",
                'data-change-notification-preview' => $playurl,
            ), rcube::Q($this->gettext('playtest')));

            $items[] = html::tag('li', null, $label . ' ' . $preview);
        }

        return html::div(null,
            html::div(null, 'Available notification files:')
            . html::tag('ul', array('class' => 'change-notification-list'), implode('', $items))
        );
    }

    private function list_user_sounds()
    {
        if (!$this->rc->user) {
            return array();
        }

        $uid = $this->rc->user->ID;
        $udir = $this->storage_dir . '/' . $uid;
        if (!is_dir($udir) || !is_readable($udir)) {
            return array();
        }

        $sounds = array();
        foreach (scandir($udir) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $udir . '/' . $name;
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $this->allowed_ext, true)) {
                continue;
            }

            $sounds[] = $uid . '/' . $name;
        }

        natcasesort($sounds);
        return array_values($sounds);
    }

    private function is_valid_user_relpath($rel)
    {
        if (!$this->rc->user || !is_string($rel) || $rel === '') {
            return false;
        }

        $uid = (string) $this->rc->user->ID;
        if (!preg_match('/^' . preg_quote($uid, '/') . '\/[A-Za-z0-9._-]+$/', $rel)) {
            return false;
        }

        $path = rtrim($this->storage_dir, '/\\') . DIRECTORY_SEPARATOR . $rel;
        return is_file($path) && is_readable($path);
    }

    private function require_settings_task()
    {
        if ($this->rc->task !== 'settings') {
            header($_SERVER['SERVER_PROTOCOL'] . ' 403 Forbidden'); exit;
        }
    }

    private function require_login()
    {
        if (!$this->rc->user) {
            header($_SERVER['SERVER_PROTOCOL'] . ' 403 Forbidden'); exit;
        }
    }

    /**
     * Manual CSRF check for multipart/form-data uploads.
     * Avoids rcube_utils paths that mis-handle multipart values.
     */
    private function check_token()
    {
        $posted = isset($_POST['_token']) ? (string)$_POST['_token'] : '';
        if ($posted === '' && !empty($_SERVER['HTTP_X_RCUBE_TOKEN'])) {
            $posted = (string)$_SERVER['HTTP_X_RCUBE_TOKEN'];
        }

        $expected = (string)$this->rc->get_request_token();

        if ($posted === '' || !hash_equals($expected, $posted)) {
            $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
                && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

            if ($is_ajax) {
                header('Content-Type: application/json; charset=utf-8');
                header($_SERVER['SERVER_PROTOCOL'] . ' 403 Forbidden');
                echo json_encode(array('ok'=>false, 'err'=>'csrf'));
                exit;
            }
            header($_SERVER['SERVER_PROTOCOL'] . ' 403 Forbidden');
            exit;
        }
    }

    private function respond_upload($ok, $err = null)
    {
        $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        if ($ok) {
            if ($is_ajax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(array('ok'=>true));
                exit;
            }
            $this->rc->output->show_message('successfullysaved', 'confirmation');
            $this->rc->output->redirect(array('_task'=>'settings','_action'=>'preferences','_section'=>'mailbox'));
        } else {
            if ($is_ajax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(array('ok'=>false,'err'=>$err));
                exit;
            }
            $this->rc->output->show_message('fileuploaderror', 'error');
            $this->rc->output->redirect(array('_task'=>'settings','_action'=>'preferences','_section'=>'mailbox'));
        }
    }

    private function rawbasename($path)
    {
        $b = basename($path);
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $b);
    }
}
