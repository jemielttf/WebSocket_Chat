import { useEffect, useState, useRef } from "react";
import "./App.css";

export default function App() {
	const [messages, setMessages] = useState([]);
	const [input, setInput] = useState("");
	const [userName, setUserName] = useState("");
	const [ws, setWs] = useState(null);

	const msgInputRef = useRef(null); // refを追加
	const nameInputRef = useRef(null); // refを追加
	const isComposing = useRef(false); // useRefで状態管理

	useEffect(() => {
		const sessionId = localStorage.getItem('session_id') || '';
		const socket 	= new WebSocket(`ws://localhost:7654?session_id=${sessionId}`);

		console.debug({socket});

		let MY_SESSION_ID = sessionId,
			isMsgIsMine;

		socket.onmessage = (event) => {
			const data = JSON.parse(event.data);

			if (data.error) {
				console.error({ data });
				return;
			} else {
				console.log({ data });
			}

			if (MY_SESSION_ID !== undefined) isMsgIsMine = MY_SESSION_ID === data.session_id;

			switch (data.type) {
				case 'session_init':
					MY_SESSION_ID = data.session_id;
					localStorage.setItem('session_id', data.session_id);
					break;

				case 'connection':
					MY_SESSION_ID = data.session_id;
					break;

				case 'user_name':
					if (!isMsgIsMine) {
						if (document.querySelector('.chat-content').classList.contains('hidden')) return;
					}

					setMessages((prev) => [...prev, {
						id: 		data.id,
						type: 		data.type,
						user_id: 	data.resource_id,
						message: 	isMsgIsMine
										? `Your ID : ${data.resource_id} is set to Name : ${data.user_name}.`
										: `"${data.user_name}" has entered the room.`,
					}]);

					document.querySelector('.chat-entry').classList.add('hidden');
					document.querySelector('.chat-content').classList.remove('hidden');
					break;

				case 'message':
					setMessages((prev) => [...prev, {
						id: 		data.id,
						type: 		data.type,
						user_id: 	data.resource_id,
						user_name: 	data.user_name,
						mine:		isMsgIsMine,
						message: 	`${data.message}`
					}]);
					break;

				case 'disconnected':
					setMessages((prev) => [...prev, {
						id: 		data.id,
						type: 		data.type,
						user_id: 	data.resource_id,
						message: 	`Disconnected from WebSocket.`
					}]);
					break;

				default:
					break;
			}
		};

		setWs(socket);

		// Cookieを取得
		// function getCookie(name) {
		// 	const value = `; ${document.cookie}`;
		// 	const parts = value.split(`; ${name}=`);

		// 	console.debug(document.cookie, value, parts);

		// 	if (parts.length === 2) return parts.pop().split(';').shift();
		// 	else					return '';
		// }

		// マウント時に#MsgInputを代入
		const msgInput 	= msgInputRef.current;
		const nameInput = nameInputRef.current;
		let timeout_id 	= 0;

		const handleCompositionStart = () => {
			clearTimeout(timeout_id);
			console.log(`Now set to isComposing.current = true`);
			isComposing.current = true;
		};

		const handleCompositionEnd = () => {
			timeout_id = setTimeout(function() {
				console.log(`Now set to isComposing.current = false`);
				isComposing.current = false;
			}, 80);
		};

		msgInput.addEventListener('compositionstart', handleCompositionStart);
		msgInput.addEventListener('compositionend', handleCompositionEnd);
		nameInput.addEventListener('compositionstart', handleCompositionStart);
		nameInput.addEventListener('compositionend', handleCompositionEnd);

		return () => {
			socket.close();

			clearTimeout(timeout_id);
			msgInput.removeEventListener('compositionstart', handleCompositionStart);
			msgInput.removeEventListener('compositionend', handleCompositionEnd);
			nameInput.removeEventListener('compositionstart', handleCompositionStart);
			nameInput.removeEventListener('compositionend', handleCompositionEnd);
		}
	}, []);

	const sendMessage = () => {
		if (ws && input.trim() !== "") {

			const data = {
				type:		'message',
				message: 	input
			};

			ws.send(JSON.stringify(data));
			setInput("");
		}
	};

	const senUserdName = () => {
		if (ws && userName.trim() !== "") {

			const data = {
				type:		'user_name',
				user_name: 	userName
			};

			ws.send(JSON.stringify(data));
			setUserName("");
		}
	};

	return (
		<div className="chat-container">
			<div className="chat-header">WebSocket Chat</div>

			<section className="chat-entry">
				<div className="chat-messages">
					<p>Input your Name</p>
				</div>
				<div className="name-input">
					<input
						id="NameInput"
						ref={nameInputRef}
						value={userName}
						onChange={(e) => setUserName(e.target.value)}
						onKeyDown={(e) => {
							if (isComposing.current) return;

							if (e.key == "Enter" && !isComposing.current) {
								e.preventDefault();
								senUserdName();
							}
						}}
						placeholder="Type your name..."
					/>
					<button onClick={senUserdName}>Send</button>
				</div>
			</section>

			<section className="chat-content hidden">
				<div className="chat-messages">
					{messages.map((msg, idx) => (
						<p key={idx} data-msg_id={msg.id} className={'type_' + msg.type} data-user_id={msg.user_id} data-mine={msg.mine}>
							{msg.type == 'message' && <span className="name">{msg.user_name} : </span>}
							<span className="message">{msg.message}</span>
						</p>
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
			</section>
		</div>
	);
}