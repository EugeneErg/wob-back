// Полный путь автора через настоящий HTTP: вход, история, главы, уровни,
// связи, релиз, прохождение, канон. Без браузера, но через ту же дверь.
const API = 'http://127.0.0.1:8000'
let cookie = ''
const jar = (res) => {
  const set = res.headers.getSetCookie?.() || []
  for (const c of set) {
    const [kv] = c.split(';')
    const [k] = kv.split('=')
    cookie = cookie.split('; ').filter(Boolean).filter((p) => !p.startsWith(k + '=')).concat(kv).join('; ')
  }
}
// Токен берётся из куки и уходит заголовком — ровно как это делает api.js.
const token = () => decodeURIComponent(
  (cookie.split('; ').find((p) => p.startsWith('XSRF-TOKEN=')) || '').split('=')[1] || '',
)
const call = async (method, path, body) => {
  const res = await fetch(API + path, {
    method,
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      Cookie: cookie,
      'X-XSRF-TOKEN': token(),
    },
    body: body === undefined ? undefined : JSON.stringify(body),
  })
  jar(res)
  const text = await res.text()
  const data = text ? JSON.parse(text) : null
  if (!res.ok) throw new Error(`${method} ${path} -> ${res.status} ${text.slice(0, 200)}`)
  return data
}
const step = (n, what) => console.log(`  ${n}. ${what}`)

await fetch(API + '/sanctum/csrf-cookie').then(jar)

const me = await call('POST', '/api/auth/dev', { email: 'flow@wob.local' })
step(1, `вход как ${me.user.email}`)

const story = await call('POST', '/api/stories', {
  title: 'Проверка пути', cover: '#123', intro: '',
  chapter: { title: 'Глава 1', image: '#234' },
})
const S = story.id
step(2, `история ${S}, глава ${story.chapterId}`)

const ch2 = await call('POST', `/api/stories/${S}/chapters`, { title: 'Глава 2', image: '#345' })
step(3, `вторая глава ${ch2.id}`)

const a = await call('POST', `/api/stories/${S}/levels`, { chapterId: story.chapterId, name: 'Начало', x: 20, y: 40 })
const b = await call('POST', `/api/stories/${S}/levels`, { chapterId: story.chapterId, name: 'Середина', x: 50, y: 50 })
const c = await call('POST', `/api/stories/${S}/levels`, { chapterId: ch2.id, name: 'Финал', x: 40, y: 40 })
step(4, 'три уровня созданы')

await call('PATCH', `/api/stories/${S}/chapters/${story.chapterId}/nodes/${a.nodeId}`, { x: 25, y: 45 })
await call('PATCH', `/api/stories/${S}/chapters/${story.chapterId}/nodes/${a.nodeId}`, { name: 'Первый шаг', image: '#456', outro: '' })
step(5, 'точка подвинута и подписана')

await call('PATCH', `/api/stories/${S}/chapters/${story.chapterId}`, { title: 'Начало пути', image: '#567', map: '#678' })
step(6, 'глава переименована, фон карты задан')

await call('POST', `/api/stories/${S}/links`, { from: a.nodeId, to: b.nodeId })
await call('POST', `/api/stories/${S}/links`, { from: b.nodeId, to: c.nodeId })
step(7, 'связи проведены')

let loop = 'нет'
try { await call('POST', `/api/stories/${S}/links`, { from: c.nodeId, to: a.nodeId }) ; loop = 'ПРОШЛА — плохо' }
catch { loop = 'отвергнута' }
step(8, `попытка кольца: ${loop}`)

await call('DELETE', `/api/stories/${S}/links/${a.nodeId}/${b.nodeId}`)
await call('POST', `/api/stories/${S}/links`, { from: a.nodeId, to: b.nodeId })
step(9, 'связь снята и поставлена заново')

for (const l of [a, b, c]) {
  await call('PUT', `/api/stories/${S}/levels/${l.id}`, {
    name: 'Уровень', width: 1600, height: 900, gravity: { x: 0, y: 1800 }, goal: 1,
    entities: [{ id: l.id + '-g', type: 'terrain', data: { x: 0, y: 820, w: 1600, h: 80 } }],
    hot: [], image: '',
  })
}
step(10, 'содержимое уровней сохранено')

await call('PATCH', `/api/stories/${S}`, { title: 'Проверка пути', cover: '#123', intro: '', startNodeId: a.nodeId })
step(11, 'начало истории назначено на точку')

const rel = await call('POST', `/api/stories/${S}/publish`)
step(12, `релиз ${rel.number}`)

const before = (await call('GET', '/api/catalog')).published.map((x) => x.id)
step(13, `до прохождения на витрине: ${before.includes(S) ? 'ЕСТЬ — плохо' : 'нет'}`)

const slot = await call('POST', `/api/stories/${S}/slots`, { name: 'Прогон' })
for (const l of [a, b, c]) {
  await call('POST', '/api/progress/complete', { storyId: S, levelId: l.id, slotId: slot.id })
}
step(14, 'автор прошёл каждый уровень')

const after = (await call('GET', '/api/catalog')).published.map((x) => x.id)
step(15, `после прохождения на витрине: ${after.includes(S) ? 'есть' : 'НЕТ — плохо'}`)

const play = await call('GET', `/api/catalog/${S}`)
step(16, `играется: глав ${play.chapters.length}, релиз ${play.version}, старт ${play.startNodeId ? 'задан' : 'НЕТ'}`)

const canon = (await call('GET', '/api/catalog')).canon.map((x) => x.title)
step(17, `канон из сида: ${canon.join(', ') || 'пусто'}`)

const del = await call('DELETE', `/api/stories/${S}/chapters/${ch2.id}`).then(() => 'удалена').catch((e) => 'ОШИБКА ' + e.message)
step(18, `вторая глава: ${del}`)
