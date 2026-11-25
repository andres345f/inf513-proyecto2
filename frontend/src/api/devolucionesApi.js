import { clienteHttp } from './clienteHttp'

const datos = (r) => r.data

/** Devoluciones: solicitudes del cliente y resolución del Personal de FEDER. */
export const devolucionesApi = {
  mias: () => clienteHttp.get('/mis-devoluciones').then(datos),
  solicitar: (solicitud) => clienteHttp.post('/mis-devoluciones', solicitud).then(datos),
  listar: (filtros) => clienteHttp.get('/gestion/devoluciones', { params: filtros }).then(datos),
  aprobar: (id, decision) => clienteHttp.post(`/gestion/devoluciones/${id}/aprobar`, decision).then(datos),
  rechazar: (id, motivo) => clienteHttp.post(`/gestion/devoluciones/${id}/rechazar`, { motivo }).then(datos),
  completar: (id) => clienteHttp.post(`/gestion/devoluciones/${id}/completar`).then(datos),
}
