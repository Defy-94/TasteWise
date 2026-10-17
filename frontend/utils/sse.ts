/**
 * AI 对话流式客户端（SSE over wx.request 分块传输）
 * 小程序没有 EventSource，只能用 wx.request({ enableChunked: true }) + onChunkReceived，
 * 再把「字节分块→文本」(utils/utf8.ts) 与「文本→事件」(utils/sseParser.ts) 接到网络上。
 * enableChunked 需基础库 ≥ 2.20.2，调用方先用 supportsChunked() 判断
 */
import { BASE_URL, getToken } from './api';
import { createUtf8StreamDecoder } from './utf8';
import { createSseParser } from './sseParser';
import { ChatMessage, RecommendDish, RepeatOrderCard } from './types';

/** done 事件携带的完整结果 */
export interface StreamDonePayload {
  reply: string;
  recommend_dishes: RecommendDish[];
  has_recommend: boolean;
  repeat_order?: RepeatOrderCard | null;
}

export interface StreamCallbacks {
  /** 每收到一段增量文本 */
  onDelta?: (text: string) => void;
  /** 全部结束，拿到完整回复与推荐卡片 */
  onDone?: (payload: StreamDonePayload) => void;
  /** 出错（网络错误、限流 429、AI 服务异常等） */
  onError?: (msg: string) => void;
}

/** 用于中途取消（例如用户离开页面） */
export interface StreamTask {
  abort: () => void;
}

/**
 * 当前基础库是否支持分块接收
 * 低于 2.20.2 不会触发 onChunkReceived，请求会退化成等全部返回，不如走非流式接口
 */
export function supportsChunked(): boolean {
  try {
    const info = wx.getSystemInfoSync();
    const version = info && info.SDKVersion ? info.SDKVersion : '';

    if (!version) {
      return false;
    }

    return compareVersion(version, '2.20.2') >= 0;
  } catch (e) {
    return false;
  }
}

/** 比较版本号：负数 v1<v2，0 相等，正数 v1>v2 */
function compareVersion(v1: string, v2: string): number {
  const a = v1.split('.');
  const b = v2.split('.');
  const len = Math.max(a.length, b.length);

  while (a.length < len) {
    a.push('0');
  }
  while (b.length < len) {
    b.push('0');
  }

  for (let i = 0; i < len; i++) {
    const n1 = parseInt(a[i], 10) || 0;
    const n2 = parseInt(b[i], 10) || 0;
    if (n1 > n2) {
      return 1;
    }
    if (n1 < n2) {
      return -1;
    }
  }

  return 0;
}

/**
 * 发起一次流式对话
 *
 * 与服务端的约定见 backend/api/ai/chat_stream.php：
 *   data: {"type":"delta","text":"..."}   多次
 *   data: {"type":"done","reply":"...","recommend_dishes":[...]}
 *   data: {"type":"error","msg":"..."}
 *   data: [DONE]
 */
export function streamChat(
  message: string,
  history: ChatMessage[],
  callbacks: StreamCallbacks
): StreamTask {
  const decode = createUtf8StreamDecoder();

  let raw = '';          // 解码后的全部文本（用于识别「不是 SSE」的响应）
  let doneSeen = false;  // 是否已收到 done
  let deltaSeen = false; // 是否收到过 delta
  let settled = false;   // 是否已经有结论（避免重复回调）
  let aborted = false;

  /** 统一出口：报错（只报一次） */
  function reportError(msg: string): void {
    if (settled || aborted) {
      return;
    }
    settled = true;
    if (callbacks.onError) {
      callbacks.onError(msg);
    }
  }

  const parser = createSseParser(function (event) {
    if (aborted) {
      return;
    }

    if (event.type === 'delta') {
      deltaSeen = true;
      if (callbacks.onDelta) {
        callbacks.onDelta(String(event.text || ''));
      }
      return;
    }

    if (event.type === 'done') {
      doneSeen = true;
      settled = true;
      if (callbacks.onDone) {
        callbacks.onDone({
          reply: String(event.reply || ''),
          recommend_dishes: event.recommend_dishes || [],
          has_recommend: !!event.has_recommend,
          repeat_order: event.repeat_order || null,
        });
      }
      return;
    }

    if (event.type === 'error') {
      reportError(String(event.msg || 'AI 服务异常'));
    }
  });

  /** 把一段文本喂给解析器，同时留一份原文用于兜底判断 */
  function feed(text: string): void {
    if (text === '') {
      return;
    }
    raw += text;
    parser(text);
  }

  /**
   * 请求结束但一直没收到 done 时的兜底判断
   *
   * 典型场景：被限流时后端返回的是普通 JSON（{"code":429,...}）而不是 SSE，
   * 此时既没有 delta 也没有 done，必须把 msg 提出来告诉用户。
   */
  function finalize(): void {
    if (settled || aborted) {
      return;
    }

    const text = raw.trim();

    if (text === '') {
      reportError('没有收到任何回复，请重试');
      return;
    }

    try {
      const json = JSON.parse(text);
      if (json && json.msg) {
        reportError(String(json.msg));
        return;
      }
    } catch (e) {
      // 不是 JSON，往下走
    }

    reportError('回复异常，请重试');
  }

  const header: Record<string, string> = {
    'Content-Type': 'application/json',
    Accept: 'text/event-stream',
  };

  const token = getToken();
  if (token) {
    header.token = token;
  }

  const task = wx.request({
    url: BASE_URL + '/api/ai/chat_stream.php',
    method: 'POST',
    data: JSON.stringify({ message: message, history: history }),
    header: header,
    enableChunked: true,
    responseType: 'arraybuffer',
    timeout: 60000,

    success(res: WechatMiniprogram.RequestSuccessCallbackResult) {
      if (aborted) {
        return;
      }

      // 分块回调万一没触发（个别环境/代理会这样），
      // 这里再拿完整响应体补一次，保证功能不会因此失效
      if (!doneSeen && res && res.data) {
        if (res.data instanceof ArrayBuffer) {
          feed(decode(new Uint8Array(res.data)));
        } else if (typeof res.data === 'string') {
          feed(res.data);
        } else {
          // 被当成对象解析了（后端返回 JSON 错误时可能这样）
          const json = res.data as any;
          if (json && json.msg) {
            reportError(String(json.msg));
            return;
          }
        }
      }

      finalize();
    },

    fail(res: WechatMiniprogram.GeneralCallbackResult) {
      if (aborted) {
        return;
      }
      reportError('网络异常，请检查后端服务');
      if (res && res.errMsg) {
        console.error('流式对话请求失败：', res.errMsg);
      }
    },
  });

  // 分块接收：字节 → 文本 → 事件
  if (task && typeof task.onChunkReceived === 'function') {
    task.onChunkReceived(function (res: WechatMiniprogram.OnChunkReceivedCallbackResult) {
      if (aborted || settled) {
        return;
      }
      feed(decode(new Uint8Array(res.data)));
    });
  } else {
    // 没有分块能力：让 success 里的完整响应体兜底
    console.warn('当前环境不支持 onChunkReceived，将等待完整响应');
  }

  return {
    abort() {
      aborted = true;
      if (task && typeof task.abort === 'function') {
        task.abort();
      }
    },
  };
}
