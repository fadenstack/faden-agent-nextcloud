import type { AgentChatViewModel } from '@fadenstack/ui'

/** Public companion SDK contract. The published 0.2 SDK deliberately has no fallback. */
interface CommandChat {
	invokeTool(name: string, args: Record<string, unknown>, options: { origin: 'user_history'; label?: string }): Promise<unknown>
}
export function commandChat(chat: AgentChatViewModel): CommandChat | undefined {
	const candidate = chat as AgentChatViewModel & Partial<CommandChat>
	return typeof candidate.invokeTool === 'function' ? candidate as CommandChat : undefined
}
