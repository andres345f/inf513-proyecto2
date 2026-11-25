import { clienteHttp } from './clienteHttp'

const datos = (r) => r.data

export const notificacionesApi = {
  listar: (filtros) => clienteHttp.get('/notificaciones', { params: filtros }).then(datos),
  contador: () => clienteHttp.get('/notificaciones/contador').then(datos),
  leer: (id) => clienteHttp.post(`/notificaciones/${id}/leer`).then(datos),
  leerTodas: () => clienteHttp.post('/notificaciones/leer-todas').then(datos),
}

/** Evento para que la campana del encabezado actualice su contador. */
export const EVENTO_NOTIFICACIONES = 'feder:notificaciones'
