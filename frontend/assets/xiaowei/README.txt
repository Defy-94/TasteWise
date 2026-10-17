小味（智能点餐助手形象）素材目录
================================================
状态：图片已处理完毕（白底抠透明 + 统一尺寸 + 压缩），已接入代码

一、目录结构
------------------------------------------------
frontend/                          【小程序根目录 miniprogramRoot = ""】
├─ assets/xiaowei/
│  ├─ diffs/                      ★ 6 张状态差分（600x600 透明 PNG），也是全站兜底图
│  └─ ui/                         头像 / 指引立绘 / tab 图标源图
├─ images/                        tabBar 实际引用的图标
└─ components/xiaowei-stage/      小味舞台区组件

说明：早期的参考图、处理脚本与剪影源图不在小程序包内，也不随仓库提交；
需要重新生成素材时，在本机临时目录处理即可。

二、最终文件清单与体积
------------------------------------------------
frontend\assets\xiaowei\diffs\
  xiaowei-idle.png        58.2 KB   微笑待命
  xiaowei-greeting.png    57.1 KB   举手招呼
  xiaowei-thinking.png    58.1 KB   思考中
  xiaowei-happy.png       61.5 KB   开心（有推荐 / 加购成功）
  xiaowei-sorry.png       56.1 KB   为难苦笑
  xiaowei-goodbye.png     53.0 KB   鞠躬道谢
  ---------------------------------------------
  6 张合计               约 344 KB

  已删除：xiaowei-searching.png（翻菜单）、xiaowei-proud.png（得意）。
  理由：点餐场景 AI 响应很快，不存在"长时间翻菜单"；加购成功直接用 happy 表达。
  页面若误传这两个状态名，组件会分别映射为 thinking / happy（见 STATE_ALIAS）。

frontend\assets\xiaowei\ui\
  xiaowei-avatar.png       89.1 KB   600x600 彩色头像（消息头像 / 顶部信息条 / tab 选中态 / 我的页小头像）
  xiaowei-pointing.png       68 KB   600x600 指引立绘（菜品详情页大图左下角，手指指向菜名）
  xiaowei-pointing-sm.png  15.8 KB   240x240 指引立绘小图（首页小味浮窗）
  xiaowei-tab-active.png   10.4 KB   162x162 彩色头像（tab 选中态源图）
  xiaowei-tab.png           1.3 KB   162x162 中间透明的灰色轮廓（tab 未选中态）
  5 张合计                 约 185 KB

  【兜底图已合并】原先还有一张 ui\xiaowei-fallback.png（240x240 兜底图）。
  它和 diffs\xiaowei-sorry.png 表达的是同一件事（"出问题了"），
  两张并存只会让包体积变大、也没人分得清该用哪张，因此已删除，
  全站统一用 diffs\xiaowei-sorry.png 作兜底（含组件的 FALLBACK_SRC）。

frontend\images\                     tabBar 实际引用（app.json 写的是相对根目录路径）
  ai.png                   0.4 KB    81x81  浅灰剪影 = 未选中
  ai_active.png            3.8 KB    81x81  彩色头像 = 选中

包内素材总计约 550 KB（主包上限 2MB，余量充足）

三、处理规格（已执行）
------------------------------------------------
  1. 白底抠成透明：洪水填充 + 抗锯齿软边；角色内部白色（厨师帽、白围巾）保留
  2. 去杂点：只保留最大连通区域
  3. 对齐：差分图按内容包围盒统一缩放系数 0.598、整体居中，人物大小与站位一致
  4. 画布：每张统一 600x600 透明 PNG
  5. 压缩：调色板量化 192 色 + PNG optimize，单张均 <= 62KB

四、状态
------------------------------------------------
  6 张差分全部为清晰版。三视图、废稿图已按要求不再保留。
  tab 未选中态由深蓝剪影重新着色为浅灰，匹配 tabBar 的 color: #999999；
  tab 选中态用彩色头像，匹配 selectedColor（当前主色 #11B3D4，即 app.json 里的取色）。

五、实现约定（已落地在 components/xiaowei-stage 与 pages/aiChat）
------------------------------------------------
  1. 路径统一走常量，便于将来换成 CDN：
       const STAGE_BASE = '/assets/xiaowei/diffs/';
       const AVATAR_SRC = '/assets/xiaowei/ui/xiaowei-avatar.png';   // 在 pages/aiChat/aiChat.ts
       const FALLBACK_SRC = STAGE_BASE + 'xiaowei-sorry.png';        // 兜底统一用 sorry
  2. <image> 带 binderror 兜底，加载失败回落到 FALLBACK_SRC
     （目前只落在小味自己的图上；菜品/购物车的外链图还没有 binderror）
  3. 状态切换有最短展示时长（dwell）与入场动画，避免闪烁
  4. 页面布局：方案 A（上舞台区 + 中消息区 + 下输入区），
     空对话时舞台区放大为欢迎态
  5. 状态映射（对应 pages/aiChat/aiChat.ts）：
       greeting  -> onLoad（首次进入）
       thinking  -> 发送后进入等待；AI 回复到达即结束
       happy     -> appendReply 带推荐菜品 / onQuickAddCart 成功
       sorry     -> failReply
       goodbye   -> 用户说"谢谢 / 再见 / bye"
       idle      -> 其余情况（常驻）
