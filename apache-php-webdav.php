<?php
// 可启用错误报告以便调试
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 身份验证配置
$AUTH_CONFIG = [
    'enabled' => true,
    'realm' => 'WebDAV Server',
    'users' => [
        'admin' => password_hash('admin123', PASSWORD_DEFAULT),
    ]
];

// 身份验证函数
function authenticate() {
    global $AUTH_CONFIG;
    if (!$AUTH_CONFIG['enabled']) {
        return true;
    }
    
    if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
        header('WWW-Authenticate: Basic realm="' . $AUTH_CONFIG['realm'] . '"');
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(401);
        echo 'Authentication required';
        exit;
    }
    
    $username = $_SERVER['PHP_AUTH_USER'];
    $password = $_SERVER['PHP_AUTH_PW'];
    
    if (!isset($AUTH_CONFIG['users'][$username]) || 
        !password_verify($password, $AUTH_CONFIG['users'][$username])) {
        header('WWW-Authenticate: Basic realm="' . $AUTH_CONFIG['realm'] . '"');
        http_response_code(401);
        echo 'Authentication failed';
        exit;
    }
    
    return true;
}

// HTTP 状态码函数
function http_code($num) {
    $codes = [
        100 => "HTTP/1.1 100 Continue",
        101 => "HTTP/1.1 101 Switching Protocols",
        200 => "HTTP/1.1 200 OK",
        201 => "HTTP/1.1 201 Created",
        202 => "HTTP/1.1 202 Accepted",
        203 => "HTTP/1.1 203 Non-Authoritative Information",
        204 => "HTTP/1.1 204 No Content",
        205 => "HTTP/1.1 205 Reset Content",
        206 => "HTTP/1.1 206 Partial Content",
        207 => "HTTP/1.1 207 Multi-Status",
        300 => "HTTP/1.1 300 Multiple Choices",
        301 => "HTTP/1.1 301 Moved Permanently",
        302 => "HTTP/1.1 302 Found",
        303 => "HTTP/1.1 303 See Other",
        304 => "HTTP/1.1 304 Not Modified",
        305 => "HTTP/1.1 305 Use Proxy",
        307 => "HTTP/1.1 307 Temporary Redirect",
        400 => "HTTP/1.1 400 Bad Request",
        401 => "HTTP/1.1 401 Unauthorized",
        402 => "HTTP/1.1 402 Payment Required",
        403 => "HTTP/1.1 403 Forbidden",
        404 => "HTTP/1.1 404 Not Found",
        405 => "HTTP/1.1 405 Method Not Allowed",
        406 => "HTTP/1.1 406 Not Acceptable",
        407 => "HTTP/1.1 407 Proxy Authentication Required",
        408 => "HTTP/1.1 408 Request Time-out",
        409 => "HTTP/1.1 409 Conflict",
        410 => "HTTP/1.1 410 Gone",
        411 => "HTTP/1.1 411 Length Required",
        412 => "HTTP/1.1 412 Precondition Failed",
        413 => "HTTP/1.1 413 Request Entity Too Large",
        414 => "HTTP/1.1 414 Request-URI Too Large",
        415 => "HTTP/1.1 415 Unsupported Media Type",
        416 => "HTTP/1.1 416 Requested range not satisfiable",
        417 => "HTTP/1.1 417 Expectation Failed",
        500 => "HTTP/1.1 500 Internal Server Error",
        501 => "HTTP/1.1 501 Not Implemented",
        502 => "HTTP/1.1 502 Bad Gateway",
        503 => "HTTP/1.1 503 Service Unavailable",
        504 => "HTTP/1.1 504 Gateway Time-out"
    ];
    return isset($codes[$num]) ? $codes[$num] : "HTTP/1.1 500 Internal Server Error";
}

function response_http_code($num) {
    header(http_code($num));
}

// XML 响应生成函数
function response_basedir($dir, $lastmod, $status) {
    $lastmod = gmdate("D, d M Y H:i:s", $lastmod)." GMT";
    return <<<EOF
<d:response>
    <d:href>{$dir}</d:href>
    <d:propstat>
        <d:prop>
            <d:getlastmodified>{$lastmod}</d:getlastmodified>
            <d:resourcetype>
                <d:collection/>
            </d:resourcetype>
        </d:prop>
        <d:status>{$status}</d:status>
    </d:propstat>
</d:response>
EOF;
}

function response_dir($dir, $lastmod, $status) {
    $lastmod = gmdate("D, d M Y H:i:s", $lastmod)." GMT";
    return <<<EOF
<d:response>
    <d:href>{$dir}</d:href>
    <d:propstat>
        <d:prop>
            <d:resourcetype>
                <d:collection/>
            </d:resourcetype>
            <d:getlastmodified>{$lastmod}</d:getlastmodified>
            <d:displayname/>
        </d:prop>
        <d:status>{$status}</d:status>
    </d:propstat>
</d:response>
EOF;
}

function response_file($file_path, $lastmod, $file_length, $status) {
    $lastmod = gmdate("D, d M Y H:i:s", $lastmod)." GMT";
    $tag = md5($lastmod.$file_path);
    return <<<EOF
<d:response>
    <d:href>{$file_path}</d:href>
    <d:propstat>
        <d:prop>
            <d:resourcetype/>
            <d:getcontentlength>{$file_length}</d:getcontentlength>
            <d:getetag>"{$tag}"</d:getetag>
            <d:getcontenttype>application/octet-stream</d:getcontenttype>
            <d:displayname/>
            <d:getlastmodified>{$lastmod}</d:getlastmodified>
        </d:prop>
        <d:status>{$status}</d:status>
    </d:propstat>
</d:response>
EOF;
}

function response($text) {
    return '<?xml version="1.0" encoding="utf-8"?>' . "\n" .
           '<d:multistatus xmlns:d="DAV:">' . "\n" .
           $text . "\n" .
           '</d:multistatus>';
}

class dav {
    protected $public;
    protected $current_user;

    public function __construct() {
        $this->public = __DIR__ . '/public';
        $this->current_user = isset($_SERVER['PHP_AUTH_USER']) ? $_SERVER['PHP_AUTH_USER'] : null;
        
        // 确保 public 目录存在
        if (!is_dir($this->public)) {
            mkdir($this->public, 0755, true);
        }
    }

    public function options() {
        header('DAV: 1, 2');
        header('MS-Author-Via: DAV');
        header('Allow: OPTIONS, GET, HEAD, PUT, POST, DELETE, PROPFIND, PROPPATCH, MKCOL, COPY, MOVE, LOCK, UNLOCK');
        header('Content-Length: 0');
        response_http_code(200);
    }

    public function head() {
        if (!authenticate()) return;
        
        $path = $this->getRequestPath();
        if (is_file($path)) {
            header('Content-Type: application/octet-stream');
            header('Content-Length: ' . filesize($path));
            $lastmod = filemtime($path);
            header('Last-Modified: ' . gmdate("D, d M Y H:i:s", $lastmod) . " GMT");
        } else {
            response_http_code(404);
        }
    }

    public function get() {
        if (!authenticate()) return;
        
        $path = $this->getRequestPath();
        if (is_file($path)) {
            header('Content-Type: application/octet-stream');
            readfile($path);
        } else {
            response_http_code(404);
        }
    }

    public function put() {
        if (!authenticate()) return;
        
        $path = $this->getRequestPath();
        $dir = dirname($path);
        
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        $input = fopen("php://input", 'r');
        $output = fopen($path, 'w');
        
        if ($input && $output) {
            stream_copy_to_stream($input, $output);
            fclose($input);
            fclose($output);
            response_http_code(201);
        } else {
            response_http_code(500);
        }
    }

    public function propfind() {
        if (!authenticate()) return;
        
        try {
            $path = $this->getRequestPath();
            
            if (!file_exists($path)) {
                response_http_code(404);
                return;
            }
            
            $depth = isset($_SERVER['HTTP_DEPTH']) ? (int)$_SERVER['HTTP_DEPTH'] : 1;
            $dav_base_dir = $this->getDavBasePath();
            
            $response_text = '';
            
            if ($depth === 0) {
                // 只返回请求的资源本身
                if (is_file($path)) {
                    $response_text = response_file(
                        $dav_base_dir, 
                        filemtime($path), 
                        filesize($path), 
                        http_code(200)
                    );
                } else {
                    $response_text = response_basedir(
                        $dav_base_dir, 
                        filemtime($path), 
                        http_code(200)
                    );
                }
            } else {
                // Depth 1 或更高 - 返回资源及其直接子项
                $response_text = response_basedir(
                    $dav_base_dir, 
                    filemtime($path), 
                    http_code(200)
                );
                
                if (is_dir($path)) {
                    $files = scandir($path);
                    
                    foreach ($files as $file) {
                        if ($file === '.' || $file === '..') {
                            continue;
                        }
                        
                        $file_path = $path . '/' . $file;
                        $file_dav_path = $dav_base_dir . '/' . rawurlencode($file);
                        
                        if (is_dir($file_path)) {
                            $response_text .= response_dir(
                                $file_dav_path,
                                filemtime($file_path),
                                http_code(200)
                            );
                        } else {
                            $response_text .= response_file(
                                $file_dav_path,
                                filemtime($file_path),
                                filesize($file_path),
                                http_code(200)
                            );
                        }
                    }
                }
            }
            
            response_http_code(207);
            header('Content-Type: text/xml; charset="utf-8"');
            echo response($response_text);
            
        } catch (Exception $e) {
            response_http_code(500);
        }
    }

    public function delete() {
        if (!authenticate()) return;
        
        $path = $this->getRequestPath();
        if (file_exists($path)) {
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
            response_http_code(200);
        } else {
            response_http_code(404);
        }
    }
    
    private function deleteDirectory($dir) {
        if (!is_dir($dir)) return false;
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }
        return rmdir($dir);
    }

    public function lock() {
        if (!authenticate()) return;
        response_http_code(501);
    }

    public function proppatch() {
        if (!authenticate()) return;
        response_http_code(501);
    }

    public function mkcol() {
        if (!authenticate()) return;
        
        $path = $this->getRequestPath();
        if (!file_exists($path)) {
            if (mkdir($path, 0755, true)) {
                response_http_code(201);
            } else {
                response_http_code(500);
            }
        } else {
            response_http_code(405);
        }
    }

    public function move() {
        if (!authenticate()) return;
        
        $source = $this->getRequestPath();
        $destination = isset($_SERVER['HTTP_DESTINATION']) ? $this->parseDestination($_SERVER['HTTP_DESTINATION']) : null;
        
        if ($destination && file_exists($source)) {
            if (rename($source, $destination)) {
                response_http_code(201);
            } else {
                response_http_code(500);
            }
        } else {
            response_http_code(400);
        }
    }
    
    // 辅助方法
    private function getRequestPath() {
        $path_info = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '';
        $relative_path = ltrim($path_info, '/');
        return $this->public . '/' . $relative_path;
    }
    
    private function getDavBasePath() {
        $path_info = isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '';
        $script_name = $_SERVER['SCRIPT_NAME'];
        
        // 构建完整的 DAV 路径
        $dav_path = $script_name . $path_info;
        if ($dav_path === '') {
            $dav_path = '/';
        }
        
        // 确保路径以 / 结尾对于目录
        if ($dav_path !== '/' && substr($dav_path, -1) !== '/') {
            $dav_path .= '/';
        }
        
        return $dav_path;
    }
    
    private function parseDestination($destination) {
        // 从 Destination 头中提取路径
        $script_name = $_SERVER['SCRIPT_NAME'];
        $pos = strpos($destination, $script_name);
        
        if ($pos !== false) {
            $relative_path = substr($destination, $pos + strlen($script_name));
            return $this->public . '/' . ltrim($relative_path, '/');
        }
        
        return null;
    }
}

// 主执行流程
try {
    $dav = new dav();
    $request_method = strtolower($_SERVER['REQUEST_METHOD']);
    
    if (method_exists($dav, $request_method)) {
        $dav->$request_method();
    } else {
        response_http_code(405);
        header('Allow: OPTIONS, GET, HEAD, PUT, POST, DELETE, PROPFIND, PROPPATCH, MKCOL, COPY, MOVE, LOCK, UNLOCK');
    }
    
} catch (Exception $e) {
    http_response_code(500);
}
