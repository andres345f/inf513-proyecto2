import { clienteHttp } from './clienteHttp'

export const navegacionApi = {
  /** Menú del rol activo (o del visitante), definido en la base de datos. */
  obtenerMenu: () => clienteHttp.get('/menu').then((r) => r.data),

  /** Registra la visita a una página y devuelve su contador acumulado. */
  registrarVisita: (pagina) => clienteHttp.post('/visitas', { pagina }).then((r) => r.data),

  /** Búsqueda global del encabezado. */
  buscar: (texto, signal) => clienteHttp.get('/buscar', { params: { q: texto }, signal }).then((r) => r.data),
}
