import { useEffect, useState } from "react";
import "./App.css";

export default function App() {
	const [messages, setMessages] = useState([]);
	const [input, setInput] = useState("");
	const [ws, setWs] = useState(null);

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

		return () => socket.close();
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
					value={input}
					onChange={(e) => setInput(e.target.value)}
					onKeyUp={(e) => {
						if (e.key === "Enter") {
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