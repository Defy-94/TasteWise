/**
 * SSE（Server-Sent Events）文本流的增量解析器
 *
 * ============================================
 * 【它解决什么问题】
 *   网络分块可能把数据切在任意位置 —— 包括切在「一个事件的正中间」。
 *   例如服务端一次发了：
 *       data: {"type":"delta","text":"宫保"}\n\n
 *   客户端拿到的三块可能是：
 *       'data: {"type":"del'
 *       'ta","text":"宫保"}\n'
 *       '\n'
 *   所以不能「拿到一块就解析一块」，必须维护一个缓冲：
 *   只处理已经完整（以空行结束）的事件，剩下的留到下一块。
 *
 *   本模块只做「文本 → 事件对象」，不关心字节怎么解码
 *   （字节到文本由 utils/utf8.ts 负责），也不碰 wx API，
 *   因此可以脱离小程序环境单独做单元测试。
 * ============================================
 *
 * SSE 格式约定（本项目服务端就这么发）：
 *   data: <一行 JSON>\n
 *   \n                        ← 空行表示一个事件结束
 *   data: [DONE]\n\n          ← 结束标记
 *   以冒号开头的行是注释（服务端用它做连接保活），客户端应忽略
 */

/** 解析出来的事件对象（本项目固定带一个 type 字段：delta / done / error） */
export interface SseParsedEvent {
  type: string;
  [key: string]: any;
}

/**
 * 创建一个有状态的 SSE 解析器
 *
 * @param onEvent 每解析出一个完整事件就回调一次
 * @returns (text: string) => void  每次把新到达的文本喂进去
 */
export function createSseParser(onEvent: (event: SseParsedEvent) => void): (text: string) => void {
  // 还没凑成完整事件的文本
  let buffer = '';

  // 上一块结尾是不是落单的 \r（\r\n 被切断的情况）
  let carryCr = false;

  /**
   * 解析一个完整的事件块（不含结尾空行）
   */
  function parseBlock(block: string): void {
    const lines = block.split('\n');

    for (let i = 0; i < lines.length; i++) {
      const line = lines[i];

      // 只有 data: 开头的行承载内容；注释行、event:、id: 等都跳过
      if (line.indexOf('data:') !== 0) {
        continue;
      }

      const payload = line.slice(5).trim();

      // 空内容与结束标记都不是事件
      if (payload === '' || payload === '[DONE]') {
        continue;
      }

      let json: any = null;
      try {
        json = JSON.parse(payload);
      } catch (e) {
        // 单行坏数据不该让整条流断掉，跳过继续
        continue;
      }

      if (json && typeof json.type === 'string') {
        onEvent(json as SseParsedEvent);
      }
    }
  }

  return function feed(text: string): void {
    if (text === '') {
      return;
    }

    let chunk = text;

    // 处理「上一块以 \r 结尾」的情况：把它和本块拼起来再判断 \r\n
    if (carryCr) {
      chunk = '\r' + chunk;
      carryCr = false;
    }

    // 本块以 \r 结尾时先扣下来，等下一块看是不是 \r\n
    if (chunk.charAt(chunk.length - 1) === '\r') {
      carryCr = true;
      chunk = chunk.slice(0, -1);
    }

    // 统一成 \n，后面只需要找 \n\n
    buffer += chunk.replace(/\r\n/g, '\n');

    let idx = buffer.indexOf('\n\n');
    while (idx !== -1) {
      parseBlock(buffer.slice(0, idx));
      buffer = buffer.slice(idx + 2);
      idx = buffer.indexOf('\n\n');
    }
  };
}
