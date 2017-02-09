<?php declare(strict_types=1);

/*
 * This file is part of the Consatan\Weibo\ImageUploader package.
 *
 * (c) Chopin Ngo <consatan@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Consatan\Weibo\ImageUploader;

use \RuntimeException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\CookieJarInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Psr\Http\Message\StreamInterface;

/**
 * Class Client
 *
 * @method self __construct(
 *     \Psr\Cache\CacheItemPoolInterface $cache = null,
 *     \GuzzleHttp\ClientInterface $http = null
 * )
 * @method self setHttps(bool $https = true)
 * @method bool login(string $username, string $password, bool $cache = true)
 * @method string upload(
 *     string|resource|\Psr\Http\Message\StreamInterface $file,
 *     string $username = '',
 *     string $password = '',
 *     array $option = []
 * )
 */
class Client
{
    /**
     * http 实例
     *
     * @var \GuzzleHttp\ClientInterface
     */
    protected $http;

    /**
     * cache 实例
     *
     * @var \Psr\Cache\CacheItemPoolInterface
     */
    protected $cache;

    /**
     * cookie 实例
     *
     * @var \GuzzleHttp\Cookie\CookieJarInterface
     */
    protected $cookie;

    /**
     * 返回的图片 URL 协议，https 或 http
     *
     * @var string
     */
    protected $protocol = 'https';

    /**
     * User-Agent
     *
     * @var string
     */
    protected $ua = '';

    /**
     * 微博帐号
     *
     * @var string
     */
    protected $username = '';

    /**
     * 微博密码
     *
     * @var string
     */
    protected $password = '';

    /**
     * @param \Psr\Cache\CacheItemPoolInterface $cache (null) Cache 实例
     *     未设置默认使用文件缓存，保存在项目根路径的 cache/weibo 目录。
     * @param \GuzzleHttp\ClientInterface $http (null) Guzzle client 实例
     * @return self
     */
    public function __construct(CacheItemPoolInterface $cache = null, ClientInterface $http = null)
    {
        $this->cookie = new CookieJar();
        $this->cache = null !== $cache ? $cache : new FilesystemAdapter('weibo', 0, __DIR__ . '/../cache');

        $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.12; rv:45.0) Gecko/20100101 Firefox/45.0';
        if (null !== $http) {
            $this->http = $http;
            $header = $http->getConfig('headers');
            // 如果是默认 UA 替换成模拟的 UA
            if (0 === strpos($header['User-Agent'], 'GuzzleHttp')) {
                $this->ua = $ua;
            }

            if (($cookie = $http->getConfig('cookies')) instanceof CookieJarInterface) {
                $this->cookie = $cookie;
            }
        } else {
            $this->http = new HttpClient(['headers' => ['User-Agent' => $ua]]);
        }
    }

    /**
     * 设置图床URL协议，支持 http 和 https。
     *
     * @param bool $https (true) 默认使用 https，设置为 false 使用 http
     * @return self
     */
    public function setHttps(bool $https = true): self
    {
        $this->protocol = $https ? 'https' : 'http';
        return $this;
    }

    /**
     * 上传图片
     *
     * @param mixed $file 要上传的文件，可以是文件路径、文件内容（字符串）、文件资源句柄
     *     或者实现了 \Psr\Http\Message\StreamInterface 接口的实体类。
     * @param string $username ('') 微博帐号
     * @param string $password ('') 微博密码
     * @param array $option ([]) 具体见 Guzzle request 的请求参数说明
     * @return string 上传成功返回对应的图片 URL
     * @throws \Consatan\Weibo\ImageUploader\ImageUploaderException
     *     文件读取失败或文件类型不正确或上传失败
     * @see http://docs.guzzlephp.org/en/latest/request-options.html
     */
    public function upload($file, string $username = '', string $password = '', array $option = []): string
    {
        $img = $file;
        if (is_string($file)) {
            // 如果是文件路径，根据文件路径获取文件句柄
            if (file_exists($file) && false === ($img = @fopen($file, 'r'))) {
                throw new ImageUploaderException("Cannot reading file $file.");
            }
        } else {
            if (!is_resource($file) && !($file instanceof StreamInterface)) {
                throw new ImageUploaderException('Upload `$file` MUST a type of string or resource '
                    . 'or instance of \Psr\Http\Message\StreamInterface, '
                    . gettype($file) . ' given.');
            }
        }

        // 如果有提供用户名密码的话，从缓存中获取登入 cookie
        if ('' !== $username && '' !== $password && !$this->login($username, $password, true)) {
            // 登入失败
            throw new ImageUploaderException('Weibo login failed, check your username and password.');
        }

        $header = [
            'Referer' => 'http://weibo.com/minipublish',
            'Accept' => 'text/html, application/xhtml+xml, image/jxr, */*',
        ];

        if (!empty($option)) {
            if (isset($option['headers'])) {
                foreach ($option['headers'] as $key => $val) {
                    $name = strtolower($key);
                    // 删除 headers 中用户自定义的必须参数
                    if ('referer' === $name || 'accept' === $name) {
                        unset($option['headers'][$key]);
                    }
                    $header[$key] = $val;
                }
            }

            // 删除不允许修改的参数
            unset($option['query'], $option['allow_redirects'], $option['multipart'], $option['headers']);
            // 删除不能和 multipart 一起使用的参数
            unset($option['json'], $option['body'], $option['form_params']);
        }

        $retry = 2;
        while ($retry--) {
            try {
                return $this->request(
                    'http://picupload.service.weibo.com/interface/pic_upload.php',
                    function (string $url) {
                        if ('' !== $url && false !== ($query = parse_url($url, PHP_URL_QUERY))) {
                            parse_str($query, $pid);
                            if (isset($pid['pid'])) {
                                $pid = $pid['pid'];
                                /**
                                 * pid 相关信息查看下面链接，可通过搜索 crc32 查看相关代码
                                 * @link http://js.t.sinajs.cn/t5/home/js/page/content/simplePublish.js
                                 *
                                 * 根据上面 js 文件代码来看，cdn 的编号应该由以下代码来决定
                                 * (($pid[9] === 'w' ? (crc32($pid) & 3) : (hexdec(substr($pid, 19, 2)) & 0xf)) + 1)
                                 * 然而当前能访问的 cdn 编号只有 1 ~ 4，而且基本上任意的
                                 * cdn 编号都能访问到同一资源，所以根据 pid 来判断 cdn 编号
                                 * 当前实际上没啥意义了，有些实现甚至直接写死 cdn 编号
                                 */
                                return $this->protocol . '://' . ($this->protocol === 'http' ? 'ww' : 'ws')
                                    . ((crc32($pid) & 3) + 1)
                                    . ".sinaimg.cn/large/$pid." . ($pid[21] === 'g' ? 'gif' : 'jpg');
                            }
                        }

                        throw new ImageUploaderException("Upload failed, redirect url: $url");
                    },
                    'POST',
                    array_merge($option, [
                        'query' => [
                            'marks' => '1',
                            'app' => 'miniblog',
                            's' => 'rdxt',
                            'markpos' => '',
                            'logo' => '',
                            'nick' => '0',
                            'url' => '',
                            'cb' => 'http://weibo.com/aj/static/upimgback.html?_wv=5&callback=STK_ijax_'
                                . substr(strval(microtime(true) * 1000), 0, 13) . '1',
                        ],
                        'multipart' => [[
                            'name' => 'pic1',
                            'contents' => $img,
                        ]],
                        'headers' => $header,
                        // 使用常规上传，将重定向到 query 里的 cb URL
                        // pid 已包含在 URL 里，故毋须进行重定向
                        'allow_redirects' => false,
                    ])
                );
            } catch (ImageUploaderException $e) {
                if (0 < $retry) {
                    // 如果第一次上传失败，尝试重新登入
                    if (!$this->login($username, $password, false)) {
                        // 如果非缓存登入失败，抛出异常
                        throw new ImageUploaderException('Weibo login failed, check your username and password.');
                    }
                } else {
                    // 如果重新登入后依然上传失败，抛出异常
                    throw $e;
                }
            }
        }
    }

    /**
     * 模拟登入微博，以获取登入信息 cookie。
     *
     * @param string $username 微博帐号，微博帐号的 md5 值将作为缓存 key
     * @param string $password 微博密码
     * @param bool $cache (true) 是否使用缓存的cookie进行登入，如果缓存不存在则创建
     * @return bool 登入成功与否
     * @throws \Consatan\Weibo\ImageUploader\ImageUploaderException 登入失败或响应异常时
     */
    public function login(string $username, string $password, bool $cache = true): bool
    {
        $this->password = $password;
        $this->username = trim($username);
        // 如果使用缓存登入且缓存里有对应用户名的缓存cookie的话，则不需要登入操作
        if ($cache && ($cookie = $this->cache->getItem(md5($this->username))->get()) instanceof CookieJarInterface) {
            $this->cookie = $cookie;
            return true;
        }

        return $this->request(
            $this->ssoLogin(),
            function (string $content) {
                if (1 === preg_match('/"\s*result\s*["\']\s*:\s*true\s*/i', $content)) {
                    // 登入成功，删除旧缓存 cookie
                    $this->cache->deleteItem(md5($this->username));
                    // 新建 或 获取 CacheItemInterface 实例
                    $cache = $this->cache->getItem(md5($this->username));
                    // 设置 cookie 信息
                    $cache->set($this->cookie);
                    // 缓存持久化
                    if (!$this->cache->save($cache)) {
                        throw new ImageUploaderException('Persists cookie cache failed.');
                    }
                    return true;
                }

                return false;
                // 该请求会返回 302 重定向，所以开启 allow_redirects
            },
            'GET',
            [
                'allow_redirects' => true,
                'headers' => [
                    'Referer' => 'http://login.sina.com.cn/sso/login.php?client=ssologin.js(v1.4.18)',
                ],
            ]
        );
    }

    /**
     * 获取 SSO 登入信息
     *
     * @return string 返回登入结果的重定向的 URL
     * @throws \Consatan\Weibo\ImageUploader\ImageUploaderException SSO 登入失败或响应异常时
     */
    protected function ssoLogin(): string
    {
        $data = $this->preLogin();
        $msg = "{$data['servertime']}\t{$data['nonce']}\n{$this->password}";

        return $this->request(
            'http://login.sina.com.cn/sso/login.php?client=ssologin.js(v1.4.18)',
            function (string $content) {
                if (1 === preg_match('/location\.replace\s*\(\s*[\'"](.*?)[\'"]\s*\)\s*;/', $content, $match)) {
                    // 返回重定向URL
                    return trim($match[1]);
                } else {
                    throw new ImageUploaderException("Login response unexpected: $content");
                }
            },
            'POST',
            [
                'headers' => ['Referer' => 'http://weibo.com/login.php'],
                'form_params' => [
                    'entry' => 'weibo',
                    'gateway' => '1',
                    'from' => '',
                    'savestate' => '7',
                    'useticket' => '1',
                    'pagerefer' => '',
                    'vsnf' => '1',
                    'su' => base64_encode(urlencode($this->username)),
                    'service' => 'miniblog',
                    'servertime' => $data['servertime'],
                    'nonce' => $data['nonce'],
                    'pwencode' => 'rsa2',
                    'rsakv' => $data['rsakv'],
                    // 加密用户登入密码
                    'sp' => bin2hex(rsa_encrypt($msg, '010001', $data['pubkey'])),
                    'sr' => '1440*900',
                    'encoding' => 'UTF-8',
                    'prelt' => '287',
                    'url' => 'http://weibo.com/ajaxlogin.php?'
                        . 'framelogin=1&callback=parent.sinaSSOController.feedBackUrlCallBack',
                    'returntype' => 'META'
                ],
            ]
        );
    }

    /**
     * 登入前获取相关信息操作
     *
     * @return array 返回登入前信息数组
     * @throws \Consatan\Weibo\ImageUploader\ImageUploaderException 请求失败或响应异常时
     */
    protected function preLogin(): array
    {
        return $this->request(
            'http://login.sina.com.cn/sso/prelogin.php?entry=weibo&callback=sinaSSOController.preloginCallBack&su='
                . urlencode(base64_encode(urlencode($this->username)))
                . '&rsakt=mod&checkpin=1&client=ssologin.js(v1.4.18)&_='
                . substr(strval(microtime(true) * 1000), 0, 13),
            function (string $content) {
                if (1 === preg_match('/^sinaSSOController.preloginCallBack\s*\((.*)\)\s*$/', $content, $match)) {
                    $json = json_decode($match[1], true);
                    if (isset($json['nonce'], $json['rsakv'], $json['servertime'], $json['pubkey'])) {
                        return $json;
                    }
                    throw new ImageUploaderException("PreLogin get an unexpected response: $match[1]");
                } else {
                    throw new ImageUploaderException("PreLogin get an unexpected response: $content");
                }
            },
            'GET',
            ['headers' => ['Referer' => 'http://weibo.com/login.php']]
        );
    }

    /**
     * 封装的 HTTP 请求方法
     *
     * @param string $url 请求 URL
     * @param callable $fn 回调函数
     * @param string $method ('GET') 请求方法
     * @param array $option ([]) 请求参数，具体见 Guzzle request 的请求参数说明
     * @return mixed 返回 `$fn` 回调函数的调用结果
     * @throws \Consatan\Weibo\ImageUploader\WeiboPuploaderException 请求失败或异常时
     * @see http://docs.guzzlephp.org/en/latest/request-options.html
     */
    protected function request(string $url, callable $fn, string $method = 'GET', array $option = [])
    {
        if ('' !== $this->ua && !isset($option['headers']['User-Agent'])) {
            $option['headers']['User-Agent'] = $this->ua;
        }

        if (!isset($option['cookies'])) {
            $option['cookies'] = $this->cookie;
        }

        try {
            $rsp = $this->http->request($method, $url, $option);
            if (200 === ($statusCode = $rsp->getStatusCode())) {
                try {
                    $content = $rsp->getBody()->getContents();
                    return $fn($content);
                } catch (RuntimeException $e) {
                    throw new ImageUploaderException('Get response content failed. '
                        . $e->getMessage(), $e->getCode(), $e);
                }
            } elseif (300 <= $statusCode && 303 >= $statusCode) {
                // 如果禁止重定向，则把重定向 URL 当参数传递
                return $fn(empty(($rsp = $rsp->getHeader('Location'))) ? '' : $rsp[0]);
            } else {
                throw new ImageUploaderException("Request failed. HTTP code: $statusCode "
                    . $rsp->getReasonPhrase());
            }
        } catch (GuzzleException $e) {
            throw new ImageUploaderException('Request failed. ' . $e->getMessage(), $e->getCode(), $e);
        }
    }
}
