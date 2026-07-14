export const SaveHistory = async ({ project, client, $, directory, worktree }) => {
  const fs = await import('fs')
  const path = await import('path')

  return {
    event: async ({ event }) => {
      if (event.type !== 'session.idle') return

      try {
        const sessionId = event.properties?.session?.id
          || event.properties?.sessionID
          || event.properties?.id

        if (!sessionId) return

        const result = await client.session.messages({ path: { id: sessionId } })
        const messages = result.data || []

        let lastUserMsg = ''
        let lastModelName = ''

        for (let i = messages.length - 1; i >= 0; i--) {
          const msg = messages[i]
          if (msg.info?.role === 'user') {
            const parts = msg.parts || []
            for (const part of parts) {
              if (part.type === 'text' && part.text) {
                lastUserMsg = part.text.trim()
                break
              }
            }
            if (lastUserMsg) break
          }
          if (msg.info?.role === 'assistant' && !lastModelName) {
            lastModelName = msg.info?.model?.display_name
              || msg.info?.model?.id
              || msg.info?.modelID
              || ''
          }
        }

        if (!lastUserMsg) return

        const dateStr = new Date().toISOString().split('T')[0]
        const historyDir = path.join(directory, '.claude', 'history')
        fs.mkdirSync(historyDir, { recursive: true })
        const historyFile = path.join(historyDir, `${dateStr}.md`)

        let lastNum = 0
        if (fs.existsSync(historyFile)) {
          const content = fs.readFileSync(historyFile, 'utf-8')
          for (const line of content.split('\n')) {
            const m = line.match(/^(\d+)\./)
            if (m) {
              const n = parseInt(m[1])
              if (n > lastNum) lastNum = n
            }
          }
        }

        let cleanMsg = lastUserMsg.replace(/\s+/g, ' ').trim()
        if (cleanMsg.length > 1000) {
          cleanMsg = cleanMsg.substring(0, 997) + '...'
        }

        const model = lastModelName.replace(/^Claude\s+/i, '') || 'opencode'
        fs.appendFileSync(historyFile, `${lastNum + 1}. ${cleanMsg} _(${model})_\n`)
      } catch (_) {
        // silently ignore errors
      }
    }
  }
}
