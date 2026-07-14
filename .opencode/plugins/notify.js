export const Notify = async ({ project, client, $, directory, worktree }) => {
  return {
    event: async ({ event }) => {
      if (event.type === 'session.idle') {
        await $`notify-send 'OPENCODE' 'Ожидание ответа'`.env({ LANG: 'ru_RU.UTF-8' }).quiet()
      }
    }
  }
}
