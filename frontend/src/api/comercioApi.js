import { clienteHttp } from './clienteHttp'

const datos = (r) => r.data
export const EVENTO_CARRITO = 'feder:carrito-actualizado'

function actualizarContador(respuesta) {
  window.dispatchEvent(new CustomEvent(EVENTO_CARRITO, { detail: respuesta.data.cantidad_productos ?? null }))
  return respuesta.data
}

/** Catálogo, carrito, pedidos y pagos (CU4, CU5 y CU6). */
export const comercioApi = {
  catalogo: (filtros) => clienteHttp.get('/catalogo', { params: filtros }).then(datos),
  filtrosCatalogo: () => clienteHttp.get('/catalogo/filtros').then(datos),
  producto: (id) => clienteHttp.get(`/catalogo/${id}`).then(datos),

  carrito: () => clienteHttp.get('/carrito').then(datos),
  agregarAlCarrito: (productoId, cantidad) => clienteHttp.post('/carrito', { producto_id: productoId, cantidad }).then(actualizarContador),
  cambiarCantidad: (productoId, cantidad) => clienteHttp.patch(`/carrito/${productoId}`, { cantidad }).then(actualizarContador),
  quitarDelCarrito: (productoId) => clienteHttp.delete(`/carrito/${productoId}`).then(actualizarContador),
  cotizarEnvio: (lat, lng) => clienteHttp.post('/carrito/cotizar-envio', { lat, lng }).then(datos),
  direccionDe: (lat, lng) => clienteHttp.get('/carrito/direccion', { params: { lat, lng } }).then(datos),

  confirmarPedido: (pedido) => clienteHttp.post('/pedidos', pedido).then(actualizarContador),
  misPedidos: (filtros) => clienteHttp.get('/mis-pedidos', { params: filtros }).then(datos),
  pedido: (id) => clienteHttp.get(`/mis-pedidos/${id}`).then(datos),
  cancelarPedido: (id, motivo) => clienteHttp.post(`/mis-pedidos/${id}/cancelar`, { motivo }).then(datos),
  pagarPedido: (id, pago) => clienteHttp.post(`/mis-pedidos/${id}/pagar`, pago).then(datos),

  misPagos: () => clienteHttp.get('/mis-pagos').then(datos),
  estadoPago: (id) => clienteHttp.get(`/mis-pagos/${id}`).then(datos),
  simularPagoQr: (id) => clienteHttp.post(`/mis-pagos/${id}/simular-qr`).then(datos),
  planesPago: () => clienteHttp.get('/planes-pago').then(datos),

  metodosPago: () => clienteHttp.get('/metodos-pago').then(datos),
  agregarMetodoPago: (tarjeta) => clienteHttp.post('/metodos-pago', tarjeta).then(datos),
  eliminarMetodoPago: (id) => clienteHttp.delete(`/metodos-pago/${id}`).then(datos),
  metodoPredeterminado: (id) => clienteHttp.post(`/metodos-pago/${id}/predeterminado`).then(datos),
}
