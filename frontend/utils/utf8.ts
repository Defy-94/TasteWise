/**
 * 增量 UTF-8 解码器
 *
 * ============================================
 * 【为什么需要它】
 *   wx.request 的 onChunkReceived 给到的分块边界是 **TCP 层面**的，
 *   和字符边界毫无关系。一个汉字在 UTF-8 里占 3 个字节，
 *   完全可能被切成「前 1 个字节在这一块、后 2 个字节在下一块」。
 *   如果每块单独解码，被切开的汉字就会变成乱码（问号或方块）。
 *
 *   所以必须把「不完整的尾巴」留到下一块、拼起来再解 ——
 *   这就是这个模块存在的唯一理由。
 *
 * 【为什么不用 TextDecoder】
 *   TextDecoder 是浏览器 / DOM 的 API，微信小程序里没有；
 *   而且它对「跨块的半个字符」需要 stream: true 配合，在小程序里也用不上。
 *   自己写一个状态机反而更可控、也更好测试。
 * ============================================
 *
 * 用法：
 *   const decode = createUtf8StreamDecoder();
 *   text1 = decode(bytes1);   // 每次喂一块 Uint8Array
 *   text2 = decode(bytes2);
 *   拼起来的 text1 + text2 就是完整正确的文本
 */

/**
 * 创建一个有状态的解码器
 *
 * @returns (bytes: Uint8Array) => string
 */
export function createUtf8StreamDecoder(): (bytes: Uint8Array) => string {
  // 上一块没解完的字节（最多 3 个：UTF-8 字符最长 4 字节，所以尾部最多残留 3 个）
  let carry: number[] = [];

  return function decode(bytes: Uint8Array): string {
    // 把残留字节拼到本次数据前面
    let buf: number[];

    if (carry.length > 0) {
      buf = carry.concat(Array.prototype.slice.call(bytes));
      carry = [];
    } else {
      buf = Array.prototype.slice.call(bytes);
    }

    let text = '';
    let i = 0;

    while (i < buf.length) {
      const b = buf[i];

      // ---------- 1. 判断这个字节是几字节字符的开头 ----------
      let extra = 0;   // 后面还要跟几个续接字节
      let cp = 0;      // 累积的码点

      if (b < 0x80) {
        extra = 0;
        cp = b;
      } else if ((b & 0xe0) === 0xc0) {
        extra = 1;
        cp = b & 0x1f;
      } else if ((b & 0xf0) === 0xe0) {
        extra = 2;
        cp = b & 0x0f;
      } else if ((b & 0xf8) === 0xf0) {
        extra = 3;
        cp = b & 0x07;
      } else {
        // 续接字节（10xxxxxx）或非法字节出现在开头：跳过，避免死循环
        i++;
        continue;
      }

      // ---------- 2. 数据不够，整段留到下一块 ----------
      if (i + extra >= buf.length) {
        carry = buf.slice(i);
        break;
      }

      // ---------- 3. 校验并累积续接字节 ----------
      let valid = true;
      for (let k = 1; k <= extra; k++) {
        const nb = buf[i + k];
        if ((nb & 0xc0) !== 0x80) {
          valid = false;
          break;
        }
        cp = (cp << 6) | (nb & 0x3f);
      }

      if (!valid) {
        // 非法序列：丢掉这个开头字节继续，不要污染后面的内容
        i++;
        continue;
      }

      // ---------- 4. 码点转字符 ----------
      if (extra === 0) {
        text += String.fromCharCode(cp);
      } else {
        // 用 fromCodePoint 才能正确处理 U+FFFF 以上的字符（emoji 等）
        text += String.fromCodePoint(cp);
      }

      i += extra + 1;
    }

    return text;
  };
}
