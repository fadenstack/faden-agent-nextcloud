import type { AgentChatViewModel } from '@fadenstack/ui'

/** Use only the published governed SDK command path; never dispatch a native inverse directly. */
export function commandChat(chat: AgentChatViewModel): Pick<AgentChatViewModel, 'invokeTool'> | undefined {
	return typeof chat.invokeTool === 'function' ? chat : undefined
}
