import { useEffect, useState, useRef } from "react";
import "./App.css";

export default function App() {
	const [messages, setMessages] = useState([]);
	const [input, setInput] = useState("");
	const [ws, setWs] = useState(null);

	const msgInputRef = useRef(null); // refを追加
	const isComposing = useRef(false); // useRefで状態管理

	useEffect(() => {
		const socket = new WebSocket("ws://localhost:7654");

		socket.onmessage = (event) => {
			const data = JSON.parse(event.data);
			console.log({ data });

			switch (data.type) {
				case 'connection':
					setMessages((prev) => [...prev, `Your ID is ${data.resource_id}`]);
					break;

				case 'message':
					setMessages((prev) => [...prev, `${data.resource_id} : ${data.message}`]);
					break;

				default:
					break;
			}
		};

		setWs(socket);

		// マウント時に#MsgInputを代入
		const msgInput = msgInputRef.current;
		let timeout_id = 0;

		const handleCompositionStart = () => {
			clearTimeout(timeout_id);
			console.log(`Now set to isComposing.current = true`);
			isComposing.current = true;
		};

		const handleCompositionEnd = () => {
			timeout_id = setTimeout(function() {
				console.log(`Now set to isComposing.current = false`);
				isComposing.current = false;
			}, 500);
		};

		msgInput.addEventListener('compositionstart', handleCompositionStart);
		msgInput.addEventListener('compositionend', handleCompositionEnd);

		return () => {
			socket.close();

			clearTimeout(timeout_id);
			msgInput.removeEventListener('compositionstart', handleCompositionStart);
			msgInput.removeEventListener('compositionend', handleCompositionEnd);
		}
	}, []);

	const sendMessage = () => {
		if (ws && input.trim() !== "") {
			ws.send(input);
			setInput("");
		}
	};

	return (
		<div className="chat-container">
			<div className="chat-header">WebSocket Chat</div>
			<div className="chat-messages">
				{messages.map((msg, idx) => (

					<p key={idx}>{msg}</p>
				))}
			</div>
			<div className="chat-input">
				<input
					id="MsgInput"
					ref={msgInputRef}
					value={input}
					onChange={(e) => setInput(e.target.value)}
					onKeyDown={(e) => {
						// console.log({isComposing, key: e.key, keyCode: e.keyCode});
						if (isComposing.current) return;

						if (e.key == "Enter" && !isComposing.current) {
							e.preventDefault();
							sendMessage();
						}
					}}
					placeholder="Type a message..."
				/>
				<button onClick={sendMessage}>Send</button>
			</div>
		</div>
	);
}