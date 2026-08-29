/**
 * 关于页面
 * 纯展示页面，无后端API调用
 */
Page({
    data: {
      appName: 'AI点餐',
      version: '1.0.0',
      description: '基于DeepSeek大模型的智能点餐小程序\n支持AI智能推荐、智能客服问答',
      techStack: 'TypeScript + PHP + MySQL + DeepSeek',
      developer: 'Zxl',
    },
  
    onLoad() {
      console.log('关于页面加载');
    },
  });