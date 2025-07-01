// src/__tests__/App.test.jsx
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import App from '../App';
import { mockServer } from '../__mocks__/WebSocket';
import { describe, beforeEach, it, expect } from 'vitest';

describe('Chat message flow', () => {
  beforeEach(() => {
    // beforeEach でメッセージハンドラをリセット
    mockServer.resetHandlers();
  });

  it('sends a message and shows it in the list', async () => {
    // ① クライアントを描画
    render(<App />);

    // ② サーバから “session_init” を送って接続確立をシミュレート
    mockServer.emit('message', JSON.stringify({
      type: 'session_init',
      session_id: 'ABC123',
    }));

    // ③ 名前入力 → Enter
    const nameInput = await screen.findByPlaceholderText(/type your name/i);
    fireEvent.change(nameInput, { target: { value: 'Ash' } });
    fireEvent.keyDown(nameInput, { key: 'Enter', code: 'Enter' });

    // ④ サーバ側で user_name 受信を検証しつつ “user_name” 応答
    await waitFor(() => {
      // 送信データを取る
      const [client] = mockServer.clients();
      expect(client).toBeDefined();
      const lastSent = client.sent.pop();
      expect(JSON.parse(lastSent)).toMatchObject({ type: 'user_name', user_name: 'Ash' });
    });

    mockServer.emit('message', JSON.stringify({
      type: 'user_name',
      resource_id: 'U1',
      user_name: 'Ash',
      id: 1,
    }));

    // ⑤ メッセージ入力 → Enter
    const msgInput = await screen.findByPlaceholderText(/type a message/i);
    fireEvent.change(msgInput, { target: { value: 'Hello!' } });
    fireEvent.keyDown(msgInput, { key: 'Enter', code: 'Enter' });

    // ⑥ クライアントが送った内容を検証
    await waitFor(() => {
      const [client] = mockServer.clients();
      const lastSent = JSON.parse(client.sent.pop());
      expect(lastSent).toEqual({ type: 'message', message: 'Hello!' });
    });

    // ⑦ サーバが “message” を返却
    mockServer.emit('message', JSON.stringify({
      type: 'message',
      resource_id: 'U1',
      user_name: 'Ash',
      id: 2,
      message: 'Hello!',
    }));

    // ⑧ 画面に表示されることを確認
    await waitFor(() => {
      const listItem = screen.getByText((_, element) =>
        element?.textContent === 'Ash : Hello!' ||
        element?.textContent === 'Ash: Hello!'
      );
      expect(listItem).toBeInTheDocument();
    });
  });
});