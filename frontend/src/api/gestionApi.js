import { clienteHttp } from './clienteHttp'

const datos = (r) => r.data

/** Operación de FEDER: procesamiento de pedidos y pagos (Personal de FEDER y administrador). */
export const gestionApi = {
  pedidos: (filtros) => clienteHttp.get('/gestion/pedidos', { params: filtros }).then(datos),
  pedido: (id) => clienteHttp.get(`/gestion/pedidos/${id}`).then(datos),
  avanzar: (id, operacion = {}) => clienteHttp.post(`/gestion/pedidos/${id}/avanzar`, operacion).then(datos),
  abastecer: (id) => clienteHttp.post(`/gestion/pedidos/${id}/abastecer`).then(datos),
  cancelar: (id, motivo) => clienteHttp.post(`/gestion/pedidos/${id}/cancelar`, { motivo }).then(datos),
  entregas: (filtros) => clienteHttp.get('/gestion/entregas', { params: filtros }).then(datos),
  avanzarEntrega: (pedidoId, operacion = {}) => clienteHttp.post(`/gestion/entregas/${pedidoId}/avanzar`, operacion).then(datos),
  pagos: (filtros) => clienteHttp.get('/gestion/pagos', { params: filtros }).then(datos),
  confirmarPago: (id) => clienteHttp.post(`/gestion/pagos/${id}/confirmar`).then(datos),
  rechazarPago: (id, motivo) => clienteHttp.post(`/gestion/pagos/${id}/rechazar`, { motivo }).then(datos),
}
