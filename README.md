# php-webdav  
一个使用 php 将您的主机映射为webdav服务的文件，兼容 apache+php 及 nginx+php 环境  
测试 php7.0 至 php8.4 均兼容  
默认连接地址是 http 或 https://你的域名/php文件的名字.php  
用户名默认是 admin ，密码默认是 admin123 ，可自行修改第11行的内容更改账号密码  
将第8行的true修改为false可以关闭验证  
默认映射 ./public 目录，修改目录的话 apache 版替换第166行的 /public 即可， nginx 版替换138行的 /public 即可  
不建议上传过大的文件，否则可能导致你的姬子爆内存崩溃  
