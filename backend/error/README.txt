HTTP 错误页（可选）
================================================
本目录是 Apache / nginx 可选的错误页素材，默认没有接线：
- backend/.htaccess 与 backend/nginx.htaccess 目前是空占位文件，未写规则；
- 如需启用，可在站点配置中用 Apache 的 ErrorDocument 或 nginx 的 error_page 指向本目录文件。

注意：本项目接口的 4xx/5xx 是 PHP 返回的 JSON，若在站点层全局接管错误页，
可能把 JSON 响应体替换成 HTML。建议只对静态资源或不存在的路径启用，或先确认网关行为。

当前文件：
  400/403/404/500/501/502/503/504/505/506/507/509/510.html
  index.html
