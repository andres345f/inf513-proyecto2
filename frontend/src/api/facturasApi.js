import { clienteHttp } from './clienteHttp'

const datos = (r) => r.data

export const facturasApi = {
  anular: (id, sustento) => clienteHttp.post(`/gestion/facturas/${id}/anulacion`, sustento).then(datos),
  listar: () => clienteHttp.get('/mis-facturas').then(datos),
  detalle: (id) => clienteHttp.get(`/mis-facturas/${id}`).then(datos),
}
