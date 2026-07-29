<?php

/**
 * Class RFCrawler
 */
class RFCrawler
{
    /**
     * Reddit URL
     */
    public const URL_REDDIT = "https://www.reddit.com";
    private const URL_REDDIT_OAUTH = "https://oauth.reddit.com";

    /**
     * Fetch types
    */
    public const FETCH_TYPE_NEW = 'new';
    public const FETCH_TYPE_HOT = 'hot';
    public const FETCH_TYPE_TOP = 'top';
    public const FETCH_TYPE_IGNORE = '';
    
    /**
     * @var string
    * 
    * URL to subreddit
    */
    private string $url;

    /**
     * @var array
    * 
    * Array of optional URL arguments
    */
    private array $args = array();

    /**
     * @var string
    * 
    * Used user agent
    */
    private string $user_agent;

    /**
     * @var array
    * 
    * Authentication credentials
    */
    private array $credentials;

    /**
     * @var string
    * 
    * Authentication bearer token
    */
    private string $auth_bearer;

    /**
     * @var bool
     *
     * Whether to use the OAuth JSON API instead of public RSS feeds.
     */
    private bool $uses_oauth;

    /**
     * Constructor for instantiation
    * 
    * @param string $url
    * @param string $user_agent
    * @param array $args
    * @param $credentials
    * @return void
    */
    public function __construct(string $url, string $user_agent = '', $args = array(), $credentials = array())
    {
        $this->uses_oauth = !empty($credentials['user']) && !empty($credentials['password']);
        $baseUrl = $this->uses_oauth ? self::URL_REDDIT_OAUTH : self::URL_REDDIT;
        $this->url = $baseUrl . '/' . trim($url, '/');
        $this->user_agent = (strlen($user_agent) > 0) ? $user_agent : 'RedHotSubs/1.0 (public RSS reader)';
        $this->args = $args;
        $this->credentials = $credentials;
        $this->auth_bearer = $this->uses_oauth ? $this->auth($credentials) : '';
    }
 
    /**
    * Fetch subreddit posts from JSON
    * 
    * @param $type
    * @param $url_filter
    * @param $url_must_contain
    * @return array
    * @throws \Exception
    */
    public function fetchPost($type = self::FETCH_TYPE_IGNORE, $url_filter = array(), $url_must_contain = array())
    {
        try {
            $result = array();
            
            $path = (strlen($type) > 0) ? $this->url . '/' . $type : $this->url;
            $url = "{$path}/." . ($this->uses_oauth ? 'json' : 'rss');
            $firstArg = false;
            
            foreach ($this->args as $key => $value) {
                if (!$firstArg) {
                    $url .= "?{$key}={$value}";
                    $firstArg = true;
                } else {
                    $url .= "&{$key}={$value}";
                }
            }
            
            if (!$this->uses_oauth) {
                return $this->fetchRssPosts($url, $url_filter, $url_must_contain);
            }

            $data = $this->request($url, [
                "Authorization: Bearer {$this->auth_bearer}"
            ]);
            
            if (is_array($data)) {
                $children = $data[0]->data->children;
            } else {
                $children = $data->data->children;
            }
            
            foreach ($children as $post) {
                $postUrl = '';
                $postTitle = '';

                if (isset($post->data->url)) {
                    $postUrl = $post->data->url;
                } else {
                    $postUrl = $post->data->link_url;
                }

                if (isset($post->data->title)) {
                    $postTitle = $post->data->title;
                } else {
                    $postTitle = $post->data->link_title;
                }

                $cont = false;
                
                foreach ($url_filter as $uf) {
                    if (strpos($postUrl, $uf) !== false) {
                        $cont = true;
                        break;
                    }
                }
                
                if ($cont === true) {
                    continue;
                }

                if (count($url_must_contain) > 0) {
                    if (!$this->containsAny($postUrl, $url_must_contain)) {
                        continue;
                    }
                }
                
                $item = new \stdClass();
                
                $item->title = $postTitle;
                $item->link = self::URL_REDDIT . "{$post->data->permalink}";
                $item->media = $postUrl;
                $item->author = $post->data->author;

                if (isset($post->data->media->reddit_video)) {
                    $qmark = strpos($post->data->media->reddit_video->fallback_url, '?');
                    if ($qmark !== false) {
                        $item->media = substr($post->data->media->reddit_video->fallback_url, 0, $qmark);
                    } else {
                        $item->media = $post->data->media->reddit_video->fallback_url;
                    }
                }
                
                $item->all = $post->data;

                $result[] = $item;
            }
            
            return $result;
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Fetch from Reddit Url
     * 
     * @return mixed
     */
    public function fetchUrl()
    {
        if (!$this->uses_oauth) {
            throw new \Exception('This Reddit metadata endpoint requires OAuth credentials. Public feeds are available through post browsing only.');
        }

        $firstArg = false;
        $url = $this->url;
        
        foreach ($this->args as $key => $value) {
            if (!$firstArg) {
                $url .= "?{$key}={$value}";
                $firstArg = true;
            } else {
                $url .= "&{$key}={$value}";
            }
        }
        
        $data = $this->request($this->url, [
            "Authorization: Bearer {$this->auth_bearer}"
        ]);

        return $data;
    }

     /**
      * Get bearer token
      * @param $credentials
      * @return mixed
      */
    public function auth($credentials)
    {
        $response = $this->request("https://www.reddit.com/api/v1/access_token", [
            'Authorization: Basic ' . base64_encode($this->credentials['user'] . ':' . $this->credentials['password'])
        ], 'grant_type=client_credentials');

        return ((isset($response->access_token)) ? $response->access_token : null);
    }

    /**
     * Normalize public Reddit RSS entries to the legacy JSON item shape.
     *
     * @param string $url
     * @param array $url_filter
     * @param array $url_must_contain
     * @return array
     * @throws \Exception
     */
    private function fetchRssPosts(string $url, array $url_filter, array $url_must_contain): array
    {
        $rss = $this->requestRaw($url, []);
        $feed = @simplexml_load_string($rss, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($feed === false) {
            throw new \Exception('Reddit public feed could not be read.');
        }

        $result = [];
        $atom = $feed->children('http://www.w3.org/2005/Atom');

        foreach ($atom->entry as $entry) {
            $media = $this->rssMediaUrl((string)$entry->content);
            if (strlen($media) === 0) {
                continue;
            }

            $cont = false;
            foreach ($url_filter as $uf) {
                if (strpos($media, $uf) !== false) {
                    $cont = true;
                    break;
                }
            }

            if ($cont || ((count($url_must_contain) > 0) && (!$this->containsAny($media, $url_must_contain)))) {
                continue;
            }

            $permalink = '';
            foreach ($entry->link as $link) {
                $attributes = $link->attributes();
                if (isset($attributes['href'])) {
                    $permalink = (string)$attributes['href'];
                    break;
                }
            }

            $author = '';
            if (isset($entry->author->name)) {
                $author = preg_replace('/^\/u\//', '', (string)$entry->author->name);
            }

            $ident = (string)$entry->id;
            $created = strtotime((string)$entry->published);
            $categoryAttributes = $entry->category->attributes();
            $item = new \stdClass();
            $item->title = (string)$entry->title;
            $item->link = $permalink;
            $item->media = $media;
            $item->author = $author;
            $item->all = (object)[
                'id' => preg_replace('/^t3_/', '', $ident),
                'name' => $ident,
                'permalink' => parse_url($permalink, PHP_URL_PATH) ?: $permalink,
                'subreddit' => isset($categoryAttributes['term']) ? trim((string)$categoryAttributes['term']) : '',
                'domain' => parse_url($media, PHP_URL_HOST) ?: '',
                'thumbnail' => $media,
                'url' => $media,
                'created_utc' => ($created === false) ? time() : $created,
                'num_comments' => 0,
                'ups' => 0
            ];
            $result[] = $item;
        }

        return $result;
    }

    /**
     * Extract the preview image from a Reddit Atom entry.
     *
     * @param string $content
     * @return string
     */
    private function rssMediaUrl(string $content): string
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        libxml_clear_errors();

        foreach ($dom->getElementsByTagName('img') as $image) {
            $src = $image->getAttribute('src');
            if (strlen($src) > 0) {
                return $src;
            }
        }

        return '';
    }

     /**
	 * Check if URL contains at least one of the required entries
	 * 
	 * @param string $url
	 * @param array $req
	 * @return bool
	 */
	private function containsAny(string $url, array $req)
	{
		$containsAny = false;
		
		foreach ($req as $item) {
			if (strpos($url, $item) !== false) {
				$containsAny = true;
				break;
			}
		}
		
		return $containsAny;
	}

    /**
     * Perform Reddit request
     * 
     * @param $url
     * @param $header
     * @param $data
     * @return mixed
     */
    private function request($url, $header, $data = null)
    {
        return json_decode($this->requestRaw($url, $header, $data));
    }

    /**
     * Perform a Reddit request and return its raw response.
     *
     * @param string $url
     * @param array $header
     * @param mixed $data
     * @return string
     * @throws \Exception
     */
    private function requestRaw(string $url, array $header, $data = null): string
    {
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->user_agent);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }

        $response = curl_exec($ch);

        if(curl_error($ch)) {
            throw new Exception(curl_error($ch));
        }

        curl_close($ch);
        
        return $response;
    }
}
