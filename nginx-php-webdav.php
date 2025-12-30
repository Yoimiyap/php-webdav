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
        200 => "HTTP/1.1 200 OK",
        201 => "HTTP/1.1 201 Created",
        204 => "HTTP/1.1 204 No Content",
        207 => "HTTP/1.1 207 Multi-Status",
        400 => "HTTP/1.1 400 Bad Request",
        401 => "HTTP/1.1 401 Unauthorized",
        403 => "HTTP/1.1 403 Forbidden",
        404 => "HTTP/1.1 404 Not Found",
        405 => "HTTP/1.1 405 Method Not Allowed",
        500 => "HTTP/1.1 500 Internal Server Error",
        501 => "HTTP/1.1 501 Not Implemented",
        503 => "HTTP/1.1 503 Service Unavailable"
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
            header('Content-Length: ' . filesize($path));
             
            // 设置正确的中文文件名下载头
            $filename = basename($path);
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
             
            if (preg_match('/MSIE|Trident/i', $user_agent)) {
                // IE 浏览器
                $filename = rawurlencode($filename);
                header('Content-Disposition: attachment; filename="' . $filename . '"');
            } elseif (preg_match('/Firefox/i', $user_agent)) {
                // Firefox 浏览器
                header('Content-Disposition: attachment; filename*="utf-8\'\'' . $filename . '"');
            } else {
                // 其他浏览器（Chrome, Safari, Edge等）
                header('Content-Disposition: attachment; filename="' . $filename . '"');
            }
             
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
                         
                        // 使用原始文件名而不是URL编码的文件名
                        // 但需要对特殊字符进行适当处理
                        $file_dav_path = $dav_base_dir . $this->encodePath($file);
                         
                        if (is_dir($file_path)) {
                            // 确保目录路径以 / 结尾
                            if (substr($file_dav_path, -1) !== '/') {
                                $file_dav_path .= '/';
                            }
                             
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
            // 确保目标目录存在
            $destDir = dirname($destination);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }
             
            if (rename($source, $destination)) {
                response_http_code(201);
            } else {
                response_http_code(500);
            }
        } else {
            response_http_code(400);
        }
    }
     
    // 辅助方法 - Nginx 兼容
    private function getRequestPath() {
        // Nginx 环境下获取请求路径
        $request_uri = $_SERVER['REQUEST_URI'];
        $script_name = $_SERVER['SCRIPT_NAME'];
         
        // 提取相对于脚本的路径
        if (strpos($request_uri, $script_name) === 0) {
            $relative_path = substr($request_uri, strlen($script_name));
        } else {
            $relative_path = $request_uri;
        }
         
        $relative_path = ltrim($relative_path, '/');
         
        // 解码 URL 编码的路径部分
        $relative_path = $this->decodePath($relative_path);
         
        $full_path = $this->public . '/' . $relative_path;
         
        // 确保路径在 public 目录内
        $real_public = realpath($this->public);
        $real_full = realpath(dirname($full_path));
         
        if ($real_full === false || strpos($real_full, $real_public) !== 0) {
            return $this->public . '/';
        }
         
        return $full_path;
    }
     
    private function getDavBasePath() {
        // Nginx 环境下构建 DAV 基础路径
        $request_uri = $_SERVER['REQUEST_URI'];
        $script_name = $_SERVER['SCRIPT_NAME'];
         
        // 构建完整的 DAV 路径
        if (strpos($request_uri, $script_name) === 0) {
            $dav_path = $script_name . substr($request_uri, strlen($script_name));
        } else {
            $dav_path = $script_name . $request_uri;
        }
         
        // 规范化路径
        $dav_path = rtrim($dav_path, '/') . '/';
        if ($dav_path === '//') {
            $dav_path = '/';
        }
         
        return $dav_path;
    }
     
    private function parseDestination($destination) {
        // 从 Destination 头中提取路径 - Nginx 兼容
        $script_name = $_SERVER['SCRIPT_NAME'];
        $host = $_SERVER['HTTP_HOST'];
         
        // 构建基础 URL
        $base_url = 'http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . $host . $script_name;
         
        if (strpos($destination, $base_url) === 0) {
            $relative_path = substr($destination, strlen($base_url));
            // 解码 URL 编码的路径
            $relative_path = $this->decodePath($relative_path);
            return $this->public . '/' . ltrim($relative_path, '/');
        }
         
        return null;
    }
     
    // 新增：路径编码函数（只对必要字符编码）
    private function encodePath($path) {
        // 只对空格和特殊字符进行编码，保持中文原样
        $search = [' ', '"', '<', '>', '#', '?', '{', '}', '|', '\\', '^', '~', '[', ']', '`'];
        $replace = array_map('rawurlencode', $search);
        return str_replace($search, $replace, $path);
    }
     
    // 新增：路径解码函数
    private function decodePath($path) {
        return rawurldecode($path);
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
