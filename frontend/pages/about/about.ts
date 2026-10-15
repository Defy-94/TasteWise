/**
 * 关于页面
 * 纯展示页面，无后端API调用
 *
 * 除了产品信息与开发信息，这里还横向展示小味的全套差分形象 ——
 * 让「关于」页也承担一点"品牌展示"的作用，顺便方便截图。
 * 展示是纯静态的：不支持点击放大，滑动浏览就够了。
 */
const STAGE_BASE = '/assets/xiaowei/diffs/';
const UI_BASE = '/assets/xiaowei/ui/';

/**
 * 小味差分清单
 *
 * 每一项对应 frontend/assets/xiaowei/ 下的真实素材；
 * 顺序按"从常驻状态到互动状态"排列，最后放两张特殊立绘。
 */
const EXPRESSION_LIST = [
  {
    label: '待命',
    desc: '平时就是她',
    src: STAGE_BASE + 'xiaowei-idle.png',
  },
  {
    label: '打招呼',
    desc: '刚进对话页',
    src: STAGE_BASE + 'xiaowei-greeting.png',
  },
  {
    label: '思考中',
    desc: '在帮你想菜',
    src: STAGE_BASE + 'xiaowei-thinking.png',
  },
  {
    label: '开心',
    desc: '有推荐 / 被点了一下',
    src: STAGE_BASE + 'xiaowei-happy.png',
  },
  {
    label: '抱歉',
    desc: '没找到或出错了',
    src: STAGE_BASE + 'xiaowei-sorry.png',
  },
  {
    label: '道谢',
    desc: '你说谢谢或再见',
    src: STAGE_BASE + 'xiaowei-goodbye.png',
  },
  {
    label: '指路',
    desc: '首页与菜品详情',
    src: UI_BASE + 'xiaowei-pointing.png',
  },
  {
    label: '头像',
    desc: '消息头像与图标',
    src: UI_BASE + 'xiaowei-avatar.png',
  },
];

Page({
    data: {
      appName: '智味',
      version: '1.0.0',
      description: '基于DeepSeek大模型的智能点餐小程序\n支持AI智能推荐、智能客服问答',
      developer: 'Defy-94',
      expressionList: EXPRESSION_LIST,
    },

    onLoad() {
      console.log('关于页面加载');
    },
  });
