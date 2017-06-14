<?php

/**
 * @Description: vk.com Wall to RSS Class
 *
 * Initial creators
 * @author tsarbas
 * @author: kadukmm <nikolay.kaduk@gmail.com>
 **/

/**
 * Refactoring and featuring:
 *   including & excluding conditions,
 *   title generation,
 *   description extraction from attachment
 *   hashtags extractor to 'category' tags
 * @author woxcab
 **/

require_once('utils.php');

class Vk2rss
{
    const HASH_TAG_PATTERN = '#([а-яёА-ЯЁa-zA-Z0-9_]+)(?:@[a-zA-Z0-9_]+)?';
    const TEXTUAL_LINK_PATTERN = '@(?:(\s*)\b(https?://\S+?)(?=[.,!?;:»”’"]?(?:\s|$))|(\()(https?://\S+?)(\))|(\([^(]*?)(\s*)\b(\s*https?://\S+?)([.,!?;:»”’"]?\s*\)))@u';
    const TEXTUAL_LINK_REPLACE_PATTERN = '$1$3$6$7<a href=\'$2$4$8\'>$2$4$8</a>$5$9';
    const TEXTUAL_LINK_REMOVE_PATTERN = '$3$6';
    const EMPTY_STRING_PATTERN = '@^(?:[\s ]*(?:<br/?>|\n)+[\s ]*)*$@u';


    /**
     * Delimiter of text from different parts of post
     */
    const VERTICAL_DELIMITER = '________________';
    /**
     * Default title value when no text in the post
     */
    const EMPTY_POST_TITLE = '[Без текста]';
    /**
     * Prefix of feed description for user wall
     */
    const USER_FEED_DESCRIPTION_PREFIX = "Стена пользователя ";
    /**
     * Prefix of feed description for group wall
     */
    const GROUP_FEED_DESCRIPTION_PREFIX = "Стена группы ";
    /**
     * Maximum title length in symbols
     */
    const MAX_TITLE_LENGTH = 80;
    /**
     * Required minimum number of symbols in second or following paragraphs in order to use its for title
     */
    const MIN_PARAGRAPH_LENGTH_FOR_TITLE = 30;
    /**
     * URL of API method that returns wall posts
     */
    const API_BASE_URL = 'http://api.vk.com/method/'; # do not change on HTTPS, connection wrapper do it itself if HTTPS is supported

    /**
     * @var int   identifier of user or group which wall going to be extracted
     */
    protected $owner_id;
    /**
     * @var string   short address of user or group which wall going to be extracted
     */
    protected $domain;
    /**
     * @var int   quantity of last posts from the wall (at most 100)
     */
    protected $count;
    /**
     * @var string   case insensitive regular expression that have to match text of post
     */
    protected $include;
    /**
     * @var string   case insensitive regular expression that have not to match text of post
     */
    protected $exclude;
    /**
     * @var bool   whether the HTML tags to be used in the feed item description
     */
    protected $disable_html;
    /**
     * @var bool   whether the post for feed should be sent by community/profile owner
     */
    protected $owner_only;
    /**
     * @var string|null   Service token for access to opened walls (there's in app settings)
     *                    or user access token for access to closed walls that opened for token creator
     */
    protected $access_token;

    /**
     * @var ProxyDescriptor|null   Proxy descriptor
     */
    protected $proxy = null;

    public function __construct($id, $count = 20, $include = null, $exclude = null, $disable_html=false,
                                $owner_only = false, $non_owner_only = false, $access_token = null,
                                $proxy = null, $proxy_type = null, $proxy_login = null, $proxy_password = null,
                                $allow_signed = null, $skip_ads = false)
    {
        if (empty($id) || empty($access_token)) {
            throw new Exception("Empty identifier of user or group or empty access/service token is passed", 400);
        }

        if (strcmp(substr($id, 0, 2), 'id') === 0 && ctype_digit(substr($id, 2))) {
            $this->owner_id = (int)substr($id, 2);
            $this->domain = null;
        } elseif (strcmp(substr($id, 0, 4), 'club') === 0 && ctype_digit(substr($id, 4))) {
            $this->owner_id = -(int)substr($id, 4);
            $this->domain = null;
        } elseif (strcmp(substr($id, 0, 5), 'event') === 0 && ctype_digit(substr($id, 5))) {
            $this->owner_id = -(int)substr($id, 5);
            $this->domain = null;
        } elseif (strcmp(substr($id, 0, 6), 'public') === 0 && ctype_digit(substr($id, 6))) {
            $this->owner_id = -(int)substr($id, 6);
            $this->domain = null;
        } elseif (is_numeric($id) && is_int(abs($id))) {
            $this->owner_id = (int)$id;
            $this->domain = null;
        } else {
            $this->owner_id = null;
            $this->domain = $id;
        }
        $this->count = $count;
        $this->include = isset($include) && $include !== '' ? preg_replace("/(?<!\\\)\//u", "\\/", $include) : null;
        $this->exclude = isset($exclude) && $exclude !== '' ? preg_replace("/(?<!\\\)\//u", "\\/", $exclude) : null;
        $this->disable_html = $disable_html;
        $this->owner_only = $owner_only;
        $this->non_owner_only = $non_owner_only;
        $this->allow_signed = $allow_signed;
        $this->skip_ads = $skip_ads;
        $this->access_token = $access_token;
        if (isset($proxy)) {
            try {
                $this->proxy = new ProxyDescriptor($proxy, $proxy_type, $proxy_login, $proxy_password);
            } catch (Exception $exc) {
                throw new Exception("Invalid proxy parameters: " . $exc->getMessage(), 400);
            }
        }
    }

    /**
     * Generate RSS feed as output
     */
    public function generateRSS()
    {
        include('FeedWriter.php');

        $outer_encoding = mb_internal_encoding();
        if (!mb_internal_encoding("UTF-8")) {
            throw new Exception("Cannot set encoding UTF-8 for multibyte strings", 500);
        }

        $connector = new ConnectionWrapper($this->proxy);

        if (!empty($this->domain) || (!empty($this->owner_id) && $this->owner_id < 0)) {
            $group_response = $this->getContent($connector, "groups.getById");
            if (property_exists($group_response, 'error') && $group_response->error->error_code != 100) {
                throw new APIError($group_response, $connector->getLastUrl());
            }
        }
        if (isset($group_response) && !property_exists($group_response, 'error') && !empty($group_response->response)) {
            $group = $group_response->response[0];
            $title = $group->name;
            $feed_description = self::GROUP_FEED_DESCRIPTION_PREFIX . $group->name;
        } else {
            try {
                $user_response = $this->getContent($connector, "users.get");
                if (property_exists($user_response, 'error')) {
                    throw new APIError($user_response, $connector->getLastUrl());
                }
            } catch (APIError $exc) {
                throw $exc->getApiErrorCode() == 113 ?
                    new Exception("Invalid user or group identifier", 400) : $exc;
            }
            if (!empty($user_response->response)) {
                $profile = $user_response->response[0];
                $title = $profile->first_name . ' ' . $profile->last_name;
                $feed_description = self::USER_FEED_DESCRIPTION_PREFIX . $profile->first_name . ' ' . $profile->last_name;
            } else {
                throw new Exception("Invalid user or group identifier", 400);
            }
        }

        $feed = new FeedWriter(RSS2);
        $id = $this->domain ? $this->domain :
            ($this->owner_id > 0 ? 'id' . $this->owner_id : 'club' . abs($this->owner_id));

        $feed->setTitle($title);
        $feed->setDescription($feed_description);
        $feed->setLink('https://vk.com/' . $id);

        $feed->setChannelElement('language', 'ru-ru');
        $feed->setChannelElement('pubDate', date(DATE_RSS, time()));


        for ($offset = 0; $offset < $this->count; $offset += 100) {
            sleep(1);
            $wall_response = $this->getContent($connector, "wall.get", $offset);
            if (property_exists($wall_response, 'error')) {
                throw new APIError($wall_response, $connector->getLastUrl());
            }

            if (empty($wall_response->response->items)) {
                break;
            }

            foreach ($wall_response->response->items as $post) {
                if ($this->owner_only
                        && ($post->owner_id != $post->from_id
                            || !is_null($this->allow_signed) && property_exists($post, 'signer_id')
                                && !$this->allow_signed)
                    || $this->non_owner_only && $post->owner_id == $post->from_id
                            && (is_null($this->allow_signed) || !property_exists($post, 'signer_id')
                                || !$this->allow_signed)
                    || $this->skip_ads && $post->marked_as_ads) {
                    continue;
                }
                $new_item = $feed->createNewItem();
                $new_item->setLink("https://vk.com/wall{$post->owner_id}_{$post->id}");
                $new_item->addElement('guid', "https://vk.com/wall{$post->owner_id}_{$post->id}");
                $new_item->setDate($post->date);

                $description = array();
                $this->extractDescription($description, $post);
                if (!empty($description) && $description[0] === self::VERTICAL_DELIMITER) {
                    array_shift($description);
                }

                foreach ($description as &$paragraph) {
                    // process internal short vk links like [id123|Name]
                    if ($this->disable_html) {
                        $paragraph = preg_replace('/\[([a-zA-Z0-9_]+)\|([^\]]+)\]/u', '$2 (https://vk.com/$1)', $paragraph);
                    } else {
                        $paragraph = preg_replace('/\[([a-zA-Z0-9_]+)\|([^\]]+)\]/u', '<a href="https://vk.com/$1">$2</a>', $paragraph);
                    }
                }

                $imploded_description = implode($this->disable_html ? PHP_EOL : '<br/>', $description);
                $new_item->setDescription($imploded_description);

                if (isset($this->include) && preg_match('/' . $this->include . '/iu', $imploded_description, $match) !== 1) {
                    continue;
                }
                if (isset($this->exclude) && preg_match('/' . $this->exclude . '/iu', $imploded_description) !== 0) {
                    continue;
                }

                $new_item->addElement('title', $this->getTitle($description));
                $new_item->addElement("slash:comments", $post->comments->count);

                preg_match_all('/' . self::HASH_TAG_PATTERN . '/u', implode(' ', $description), $hash_tags);

                foreach ($hash_tags[1] as $hash_tag) {
                    $new_item->addElement('category', $hash_tag);
                }

                $feed->addItem($new_item);
            }
        }

        $feed->generateFeed();
        mb_internal_encoding($outer_encoding);
    }

    protected function extractDescription(&$description, $post)
    {
        $par_split_regex = '@[\s ]*?(?:<br/?>|\n)+[\s ]*?@u'; # PHP 5.2.X: \s does not contain non-break space

        if (preg_match(self::EMPTY_STRING_PATTERN, $post->text) === 0) {
            $post_text = $post->text;
            if (!$this->disable_html) {
                $post_text = preg_replace(self::TEXTUAL_LINK_PATTERN,
                                          self::TEXTUAL_LINK_REPLACE_PATTERN,
                                          $post_text);
            }
            $description = array_merge($description, preg_split($par_split_regex, $post_text));
        }

        if (isset($post->attachments)) {
            foreach ($post->attachments as $attachment) {
                switch ($attachment->type) {
                    case 'photo': {
                        $photo_sizes = array_values(preg_grep('/^photo_/u',
                                                              array_keys(get_object_vars($attachment->photo))));
                        $photo_text = preg_replace('|^Original: https?://\S+\s*|u',
                                                   '',
                                                   $attachment->photo->text);

                        if (!$this->disable_html) {
                            $photo_text = preg_replace(self::TEXTUAL_LINK_PATTERN,
                                                       self::TEXTUAL_LINK_REPLACE_PATTERN,
                                                       $photo_text);
                        }
                        $huge_photo_src = $attachment->photo->{end($photo_sizes)};
                        $photo = $this->disable_html ? $huge_photo_src
                            : "<a href='{$huge_photo_src}'><img src='{$attachment->photo->{$photo_sizes[2]}}'/></a>";
                        if (preg_match(self::EMPTY_STRING_PATTERN, $photo_text) === 0) {
                            $description = array_merge($description,
                                                       array(self::VERTICAL_DELIMITER),
                                                       preg_split($par_split_regex, $photo_text),
                                                       array($photo));
                        } else {
                            array_push($description, $photo);
                        }
                        break;
                    }
                    case 'album': {
                        $thumb_sizes = array_values(preg_grep('/^photo_/u',
                                                              array_keys(get_object_vars($attachment->album->thumb))));
                        $album_title = $attachment->album->title;
                        $album_url = "https://vk.com/album" . $attachment->album->owner_id . "_" . $attachment->album->id;
                        array_push($description, self::VERTICAL_DELIMITER);
                        if ($this->disable_html) {
                            array_push($description, "Альбом «" . $album_title . "»: " . $album_url);
                        } else {
                            array_push($description, "<a href='" . $album_url . "'>Альбом «" . $album_title . "»</a>" );
                        }
                        $album_description = $attachment->album->description;
                        if (!$this->disable_html) {
                            $album_description = preg_replace(self::TEXTUAL_LINK_PATTERN,
                                                       self::TEXTUAL_LINK_REPLACE_PATTERN,
                                                       $album_description);
                        }
                        if (preg_match(self::EMPTY_STRING_PATTERN, $album_description) === 0) {
                            $description = array_merge($description, preg_split($par_split_regex, $album_description));
                        }
                        $huge_thumb_src = $attachment->album->thumb->{end($thumb_sizes)};
                        $thumb = $this->disable_html ? $huge_thumb_src
                            : "<a href='{$huge_thumb_src}'><img src='{$attachment->album->thumb->{$thumb_sizes[2]}}'/></a>";
                        array_push($description, $thumb);
                        break;
                    }
                    case 'audio': {
                        $title = "Аудиозапись {$attachment->audio->artist} — «{$attachment->audio->title}»";
                        array_push($description, $title);
                        break;
                    }
                    case 'doc': {
                        $url = parse_url($attachment->doc->url);
                        parse_str($url['query'], $params);
                        unset($params['api']);
                        unset($params['dl']);
                        $url['query'] = http_build_query($params);
                        $url = build_url($url);
                        if (!empty($attachment->doc->preview->photo)) {
                            $photos = $attachment->doc->preview->photo->sizes;
                            $preview_url = $photos[min(2, count($photos)-1)]->src;
                            $photo_url = end($photos)->src;
                            if ($this->disable_html) {
                                array_push($description, "Изображение «{$attachment->doc->title}»: {$photo_url} ({$url})");
                            } else {
                                array_push($description,
                                           self::VERTICAL_DELIMITER,
                                           "<a href='{$url}'>Изображение «{$attachment->doc->title}»</a>: <a href='{$photo_url}'><img src='{$preview_url}'/></a>");
                            }
                        } else {
                            if ($this->disable_html) {
                                array_push($description, "Файл «{$attachment->doc->title}»: {$url}");
                            } else {
                                array_push($description, "<a href='{$url}'>Файл «{$attachment->doc->title}»</a>");
                            }
                        }
                        break;
                    }
                    case 'link': {
                        if ($this->disable_html) {
                            array_push($description,
                                       self::VERTICAL_DELIMITER,
                                       "{$attachment->link->title}: {$attachment->link->url}");
                        } else {
                            $link_text = preg_match(self::EMPTY_STRING_PATTERN, $attachment->link->title) === 0 ?
                                $attachment->link->title : $attachment->link->url;
                            array_push($description,
                                       self::VERTICAL_DELIMITER,
                                       "<a href='{$attachment->link->url}'>{$link_text}</a>");
                        }
                        if (preg_match(self::EMPTY_STRING_PATTERN, $attachment->link->description) === 0) {
                            array_push($description, $attachment->link->description);
                        }
                        break;
                    }
                    case 'video': {
                        $video_text = $attachment->video->description;
                        if (!$this->disable_html) {
                            $video_text = preg_replace(self::TEXTUAL_LINK_PATTERN,
                                                       self::TEXTUAL_LINK_REPLACE_PATTERN,
                                                       $video_text);
                        }
                        $video_description = preg_match(self::EMPTY_STRING_PATTERN, $video_text) === 1 ?
                            array() : preg_split($par_split_regex, $video_text);
                        if (empty($attachment->video->title)) {
                            $content = array("Видеозапись:");
                        } else {
                            $content = array("Видеозапись «{$attachment->video->title}»:");
                        }
                        if ($video_description) {
                            array_unshift($content, self::VERTICAL_DELIMITER);
                        }
                        if ($this->disable_html) {
                            array_push($content, "https://vk.com/video{$attachment->video->owner_id}_{$attachment->video->id}");
                        } else {
                            $preview_sizes = array_values(preg_grep('/^photo_/u', array_keys(get_object_vars($attachment->video))));
                            $preview_size = isset($preview_sizes[2]) ? $preview_sizes[2] : end($preview_sizes);
                            array_push($content, "<a href='https://vk.com/video{$attachment->video->owner_id}_{$attachment->video->id}'><img src='{$attachment->video->{$preview_size}}'/></a>");
                        }
                        $description = array_merge($description, $content, $video_description);
                        break;
                    }
                    case 'page':
                        if ($this->disable_html) {
                            array_push($description,
                                       self::VERTICAL_DELIMITER,
                                       "{$attachment->page->title}: https://vk.com/page-{$attachment->page->group_id}_{$attachment->page->id}");
                        } else {
                            array_push($description,
                                       self::VERTICAL_DELIMITER,
                                       "<a href='https://vk.com/page-{$attachment->page->group_id}_{$attachment->page->id}'>{$attachment->page->title}</a>");
                        }
                        break;
                }
            }
        }

        if (isset($post->copy_history)) {
            foreach ($post->copy_history as $repost) {
                array_push($description, self::VERTICAL_DELIMITER);
                $this->extractDescription($description, $repost);
                if (end($description) === self::VERTICAL_DELIMITER) {
                    array_pop($description);
                }
            }
        }
    }

    /**
     * Get posts of wall
     *
     * @param ConnectionWrapper $connector
     * @param string $api_method   API method name
     * @param int $offset   offset for wall.get
     * @return mixed   Json VK response in appropriate PHP type
     * @throws Exception   If unsupported API method name is passed or data retrieving is failed
     */
    protected function getContent($connector, $api_method, $offset = null)
    {
        $url = self::API_BASE_URL . $api_method . '?v=5.54';
        if (isset($this->access_token)) {
            $url .= "&access_token={$this->access_token}";
        }
        switch ($api_method) {
            case "wall.get":
                if (!empty($this->domain)) {
                    $url .= "&domain={$this->domain}";
                } else {
                    $url .= "&owner_id={$this->owner_id}";
                }
                if (!empty($offset)) {
                    $count = min($this->count - $offset, 100);
                    $url .= "&offset=${offset}";
                } else {
                    $count = min($this->count, 100);
                }
                $url .= "&count={$count}";
                break;
            case "users.get":
                $url .= "&fields=first_name,last_name&user_ids=" . (!empty($this->domain) ? $this->domain : $this->owner_id);
                break;
            case "groups.getById":
                $url .= "&fields=name&group_id=" . (!empty($this->domain) ? $this->domain : abs($this->owner_id));
                break;
            default:
                throw new Exception("Passed unsupported API method name '${api_method}'", 400);
        }
        $connector->openConnection();
        $content = null;
        try {
            $content = $connector->getContent($url, null, true);
            $connector->closeConnection();
        } catch (Exception $exc) {
            $connector->closeConnection();
            throw new Exception("Failed to get content of URL ${url}: " . $exc->getMessage(), $exc->getCode());
        }
        if (!$this->disable_html) {
            $content = strtr($content, array('<' => '&lt;',
                                             '>' => '&gt;',
                                             '\"' => '&quot;',
                                             '\'' => '&apos;'));
        }
        return json_decode($content);
    }

    /**
     * Generate title using text of post
     *
     * @param $description array   post paragraphs
     * @return string   generated title
     */
    protected function getTitle($description)
    {
        foreach ($description as $par_idx => &$paragraph) {
            if (preg_match('/^\s*(?:' . self::HASH_TAG_PATTERN . '\s*)*$/u', $paragraph) === 1 // paragraph contains only hash tags
                || $paragraph === self::VERTICAL_DELIMITER) {
                unset($description[$par_idx]);
                continue;
            }
            if (!$this->disable_html) {
                $paragraph = preg_replace('/<[^>]+?>/u', '', $paragraph); // remove all tags
                $paragraph = strtr($paragraph, array('&lt;' => '<',
                                                     '&gt;' => '>',
                                                     '&quot;' => '"' ,
                                                     '&apos;' => '\''));
            }
            if ($paragraph === self::VERTICAL_DELIMITER) {
                $paragraph = "";
            } else {
                $paragraph = trim(preg_replace(self::TEXTUAL_LINK_PATTERN, self::TEXTUAL_LINK_REMOVE_PATTERN, $paragraph));
            }
        }
        if (preg_match('/^\s*$/u', implode(PHP_EOL, $description)) === 1) {
            return self::EMPTY_POST_TITLE;
        }

        if (!function_exists('remove_underscores_from_hash_tag')) {
            function remove_underscores_from_hash_tag($match)
            {
                return str_replace('_', ' ', $match[1]);
            }
        }

        $curr_title_length = 0;
        $slice_length = 0;

        foreach ($description as $par_idx => &$paragraph) {
            // hash tags (if exist) are semantic part of paragraph
            $paragraph = preg_replace_callback('/' . self::HASH_TAG_PATTERN . '/u',
                                               'remove_underscores_from_hash_tag', # anonymous function only in PHP>=5.3.0
                                               $paragraph);

            if ($curr_title_length < self::MAX_TITLE_LENGTH) {
                if (preg_match(self::EMPTY_STRING_PATTERN, $paragraph) === 1) {
                    unset($description[$par_idx]);
                    continue;
                }
                if(mb_strlen($paragraph) >= self::MIN_PARAGRAPH_LENGTH_FOR_TITLE
                   || $curr_title_length + self::MIN_PARAGRAPH_LENGTH_FOR_TITLE < self::MAX_TITLE_LENGTH) {
                    if (!in_array(mb_substr($paragraph, -1), array('.', '!', '?', ',', ':', ';'))) {
                        $paragraph .= '.';
                    }
                    $curr_title_length += mb_strlen($paragraph);
                    $slice_length += 1;
                } else {
                    break;
                }
            }
        }

        $full_title = implode(' ', array_slice($description, 0, $slice_length));
        if (mb_strlen($full_title) > self::MAX_TITLE_LENGTH) {
            $split = preg_split('/\s+/u', utf8_strrev(mb_substr($full_title, 0, self::MAX_TITLE_LENGTH)), 2);
            $full_title = utf8_strrev($split[1]);

            $last_char = mb_substr($full_title, -1);
            if (in_array($last_char, array(',', ':', ';', '-'))) {
                $full_title = mb_substr($full_title, 0, -1) . '...';
            } elseif (!in_array($last_char, array('.', '!', '?', ')'))) {
                $full_title .= '...';
            }
        }
        $full_title = mb_strtoupper(mb_substr($full_title, 0, 1)) . mb_substr($full_title, 1);
        return $full_title;
    }

}
