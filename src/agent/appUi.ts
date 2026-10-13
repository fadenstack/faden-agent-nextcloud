/**
 * What the app offers to show in an empty chat: sent in its manifest, so an administrator can take it in on the
 * agent's Apps tab (with the tools) and the panel starts with ready questions instead of an empty page. One
 * suggestion per thing the tools do (the panel shows six); the agent's own version decides what is shown, and
 * `contracts/nextcloud-pilot.json` carries the same words.
 */
export const APP_UI = {
	greeting: 'I work with your Nextcloud files. Ask me to find a document, check what it says, or write a note.',
	suggestions: [
		// nc_search_files: names and metadata, newest first
		'Find the latest supplier contract',
		// nc_search_files sorted by modification
		'Show my ten most recently changed documents',
		// nc_get_selected_file and nc_read_file
		'Summarize the selected document',
		// nc_search_in_file
		'Search the selected document for "termination"',
		// nc_list_folder
		'What is in the selected folder?',
		// nc_create_text_file, after the approval card
		'Write a summary of the selected document as a new note',
	],
} as const
