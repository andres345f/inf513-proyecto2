import { clienteHttp } from './clienteHttp'

const datos = (r) => r.data

/** Inventario por lote (personal) y portal del productor. */
export const consultasApi = {
  inventario: (filtros) => clienteHttp.get('/gestion/inventario', { params: filtros }).then(datos),
  produccionesGestion: (filtros) => clienteHttp.get('/gestion/producciones', { params: filtros }).then(datos),
  comprasGestion: (filtros) => clienteHttp.get('/gestion/compras', { params: filtros }).then(datos),
  misProducciones: () => clienteHttp.get('/productor/producciones').then(datos),
  misVentas: () => clienteHttp.get('/productor/ventas').then(datos),
  misIndicadores: () => clienteHttp.get('/productor/indicadores').then(datos),
}
