// Load test for Mealboard (Laravel): many people using the site and the door app at the same time.
//
//   node tools/load-test.mjs [--url=http://127.0.0.1:8088] [--duration=30s] [--concurrency=32] [--mix=normal|reads|api|web]
//                           [--out=perf-results/run.json]
//
// It signs in a few employees, admins and the door account, then keeps --concurrency requests in flight for
// --duration, picking each request from the mix below. It writes only what the door writes (served marks,
// heartbeats) and bookings of the test accounts: run it on a COPY of the database.
import http from 'node:http'
import { writeFileSync, mkdirSync } from 'node:fs'
import path from 'node:path'

const arg = (name, fallback) => (process.argv.find((a) => a.startsWith(`--${name}=`)) ?? `=${fallback}`).split('=').slice(1).join('=')
const BASE = new URL(arg('url', 'http://127.0.0.1:8088'))
const seconds = (s) => (s.endsWith('m') ? parseFloat(s) * 60 : parseFloat(s))
const DURATION = seconds(arg('duration', '30s'))
const CONCURRENCY = Number(arg('concurrency', 32))
const MIX = arg('mix', 'normal')
const OUT = arg('out', '')
const PASSWORD = arg('password', 'test12345')
const EMPLOYEES = arg('employees', 'demo2@demo.mealboard.test,demo9@demo.mealboard.test').split(',')
const ADMIN = arg('admin', 'akrambellirwork1@gmail.com')
const DOOR = arg('door', '')

const agent = new http.Agent({ keepAlive: true, maxSockets: CONCURRENCY + 8 })

function request(method, url, { headers = {}, body, cookies } = {}) {
  return new Promise((resolve) => {
    const started = process.hrtime.bigint()
    const data = body === undefined ? undefined : typeof body === 'string' ? body : JSON.stringify(body)
    const req = http.request(new URL(url, BASE), { method, agent, headers: { ...headers, ...(cookies?.header() ? { Cookie: cookies.header() } : {}), ...(data !== undefined ? { 'Content-Length': Buffer.byteLength(data) } : {}) } }, (res) => {
      const chunks = []
      res.on('data', (c) => chunks.push(c))
      res.on('end', () => {
        if (cookies) cookies.take(res.headers['set-cookie'])
        resolve({ status: res.statusCode, body: Buffer.concat(chunks).toString(), ms: Number(process.hrtime.bigint() - started) / 1e6, headers: res.headers })
      })
    })
    req.on('error', (e) => resolve({ status: 0, body: String(e.message), ms: Number(process.hrtime.bigint() - started) / 1e6 }))
    if (data !== undefined) req.write(data)
    req.end()
  })
}

class Jar {
  constructor() { this.c = {} }
  take(list) { for (const line of list ?? []) { const [pair] = line.split(';'); const i = pair.indexOf('='); this.c[pair.slice(0, i)] = pair.slice(i + 1) } }
  header() { return Object.entries(this.c).map(([k, v]) => `${k}=${v}`).join('; ') }
}

const csrfOf = (html) => html.match(/name="_token" value="([^"]+)"/)?.[1] ?? html.match(/<meta name="csrf-token" content="([^"]+)"/)?.[1] ?? ''

async function webSession(email) {
  const jar = new Jar()
  const page = await request('GET', '/', { cookies: jar })
  const token = csrfOf(page.body)
  const form = new URLSearchParams({ _token: token, email, password: PASSWORD }).toString()
  const r = await request('POST', '/login', { cookies: jar, body: form, headers: { 'Content-Type': 'application/x-www-form-urlencoded' } })
  if (r.status !== 302 || /\/$/.test(r.headers.location ?? '/')) throw new Error(`sign-in failed for ${email} (${r.status} -> ${r.headers.location})`)
  const home = await request('GET', r.headers.location, { cookies: jar })
  return { jar, csrf: csrfOf(home.body) || token }
}

async function apiToken(email) {
  const r = await request('POST', '/api/auth/login', { body: { email, password: PASSWORD }, headers: { 'Content-Type': 'application/json', Accept: 'application/json' } })
  if (r.status !== 200) throw new Error(`API sign-in failed for ${email}: ${r.status} ${r.body.slice(0, 100)}`)
  return JSON.parse(r.body).token
}

const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Algiers' })

async function main() {
  console.log(`Mealboard load test: ${BASE.origin}, ${CONCURRENCY} at once, ${DURATION}s, mix "${MIX}"`)
  const employees = []
  for (const e of EMPLOYEES) employees.push(await webSession(e))
  const admin = await webSession(ADMIN)
  const doorEmail = DOOR || EMPLOYEES[0]
  const token = await apiToken(doorEmail)
  const apiHeaders = { Authorization: token, Accept: 'application/json' }
  const orderIds = JSON.parse((await request('GET', `/api/orders?day=${today}&fields=id`, { headers: apiHeaders })).body).items?.map((o) => o.id) ?? []
  const pick = (list) => list[Math.floor(Math.random() * list.length)]
  const emp = () => pick(employees)

  // [weight, name, run]
  const web = [
    [20, 'employee: week page', () => request('GET', '/week', { cookies: emp().jar })],
    [8, 'employee: notifications', () => request('GET', '/notifications', { cookies: emp().jar })],
    [5, 'employee: profile', () => request('GET', '/profile', { cookies: emp().jar })],
    [4, 'employee: review a booking', () => { const s = emp(); return request('POST', '/week/review', { cookies: s.jar, body: new URLSearchParams({ _token: s.csrf }).toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } }) }],
    [5, 'admin: dashboard', () => request('GET', '/admin', { cookies: admin.jar })],
    [3, 'admin: attendance', () => request('GET', '/attendance', { cookies: admin.jar })],
    [2, 'admin: users', () => request('GET', '/users', { cookies: admin.jar })],
    [2, 'admin: serve page', () => request('GET', '/serve', { cookies: admin.jar })],
    [1, 'admin: statistics', () => request('GET', '/statistics', { cookies: admin.jar })],
    [5, 'login page', () => request('GET', '/')],
  ]
  const api = [
    [10, 'door: day bookings', () => request('GET', `/api/orders?day=${today}&fields=id,user,bool,bool2,bool3,attended_breakfast,attended_lunch,attended_dinner`, { headers: apiHeaders })],
    [6, 'door: booking rules', () => request('GET', '/api/booking-rules', { headers: { Accept: 'application/json' } })],
    [4, 'door: heartbeat', () => request('POST', '/api/mealboard/door-heartbeat', { headers: { ...apiHeaders, 'Content-Type': 'application/json' }, body: { door: 'load-test-door', name: 'Load test', pending: 0, closeAfterMinutes: 0 } })],
    [3, 'door: mark served', () => orderIds.length ? request('PATCH', `/api/orders/${pick(orderIds)}`, { headers: { ...apiHeaders, 'Content-Type': 'application/json' }, body: { attended_lunch: false } }) : request('GET', '/api/booking-rules')],
    [2, 'door: meal groups', () => request('GET', '/api/meal-groups', { headers: apiHeaders })],
    [1, 'door: everyone (badges)', () => request('GET', '/api/users?fields=id,firstname,lastname,card_id,Status,Department', { headers: apiHeaders })],
  ]
  const mixes = {
    normal: [...web, ...api],
    web,
    api,
    reads: [...web.filter(([, n]) => !n.includes('review')), ...api.filter(([, n]) => !n.includes('mark') && !n.includes('heartbeat'))],
  }
  const ONLY = arg('path', '')
  if (ONLY) mixes.only = [[1, `GET ${ONLY}`, () => request('GET', ONLY, { headers: ONLY.startsWith('/api/') ? apiHeaders : {}, cookies: ONLY.startsWith('/api/') || ONLY === '/up' || ONLY === '/' ? undefined : (ONLY.match(/^\/(admin|users|attendance|serve|statistics|invoices|diners|meals|reports|system|settings|penalties)/) ? admin.jar : emp().jar) })]]
  const plan = ONLY ? mixes.only : (mixes[MIX] ?? mixes.normal)
  const total = plan.reduce((s, [w]) => s + w, 0)
  const choose = () => { let r = Math.random() * total; for (const p of plan) { if ((r -= p[0]) < 0) return p } return plan[0] }

  const stats = {}
  const timeline = []
  let done = 0, failed = 0
  const started = Date.now()
  const end = started + DURATION * 1000
  let bucket = { at: 0, n: 0, failed: 0 }
  const tick = setInterval(() => { timeline.push(bucket); process.stdout.write(`  ${String(bucket.at).padStart(3)}s  ${bucket.n} req/s  ${bucket.failed ? bucket.failed + ' failed' : ''}\n`); bucket = { at: bucket.at + 1, n: 0, failed: 0 } }, 1000)

  async function worker() {
    while (Date.now() < end) {
      const [, name, run] = choose()
      const r = await run()
      const ok = r.status >= 200 && r.status < 400
      const s = (stats[name] ??= { n: 0, failed: 0, ms: [], codes: {} })
      s.n++; s.ms.push(r.ms); s.codes[r.status] = (s.codes[r.status] ?? 0) + 1
      done++; bucket.n++
      if (!ok) { s.failed++; failed++; bucket.failed++ }
    }
  }
  await Promise.all(Array.from({ length: CONCURRENCY }, worker))
  clearInterval(tick)
  const secs = (Date.now() - started) / 1000
  const pct = (list, p) => { const a = [...list].sort((x, y) => x - y); return a.length ? a[Math.min(a.length - 1, Math.floor(p * a.length))] : 0 }
  const all = Object.values(stats).flatMap((s) => s.ms)

  console.log(`\n${done} requests in ${secs.toFixed(1)} s = ${(done / secs).toFixed(0)} per second, ${failed} failed`)
  console.log(`median ${pct(all, 0.5).toFixed(1)} ms · 95% under ${pct(all, 0.95).toFixed(1)} ms · 99% under ${pct(all, 0.99).toFixed(1)} ms · slowest ${Math.max(...all).toFixed(0)} ms\n`)
  console.log('request'.padEnd(30) + 'count'.padStart(7) + 'median'.padStart(9) + 'p95'.padStart(9) + '  codes')
  for (const [name, s] of Object.entries(stats).sort((a, b) => b[1].n - a[1].n)) {
    console.log(name.padEnd(30) + String(s.n).padStart(7) + `${pct(s.ms, 0.5).toFixed(1)}`.padStart(9) + `${pct(s.ms, 0.95).toFixed(1)}`.padStart(9) + '  ' + JSON.stringify(s.codes))
  }
  if (OUT) {
    mkdirSync(path.dirname(OUT), { recursive: true })
    writeFileSync(OUT, JSON.stringify({ url: BASE.origin, concurrency: CONCURRENCY, duration: secs, mix: MIX, requests: done, perSecond: done / secs, failed,
      median: pct(all, 0.5), p95: pct(all, 0.95), p99: pct(all, 0.99), timeline,
      byRequest: Object.fromEntries(Object.entries(stats).map(([k, s]) => [k, { n: s.n, failed: s.failed, median: pct(s.ms, 0.5), p95: pct(s.ms, 0.95), codes: s.codes }])) }, null, 2))
    console.log(`\nsaved ${OUT}`)
  }
}
main().catch((e) => { console.error(e.message); process.exit(1) })
