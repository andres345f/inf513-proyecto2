import { clienteHttp } from './clienteHttp'

const datos = (r) => r.data

export function cuerpoProducto(producto, actualizar = false) {
  if (!producto.foto) return producto
  const form = new FormData()
  for (const [campo, valor] of Object.entries(producto)) {
    if (valor != null) form.append(campo, typeof valor === 'boolean' ? Number(valor) : valor)
  }
  if (actualizar) form.append('_method', 'PUT')
  return form
}

/** Mantenimiento del catálogo (CU4): productos y categorías. */
export const catalogoGestionApi = {
  productos: (filtros) => clienteHttp.get('/gestion/productos', { params: filtros }).then(datos),
  crearProducto: (producto) => clienteHttp.post('/gestion/productos', cuerpoProducto(producto)).then(datos),
  actualizarProducto: (id, producto) => {
    const ruta = `/gestion/productos/${id}`
    return (producto.foto ? clienteHttp.post(ruta, cuerpoProducto(producto, true)) : clienteHttp.put(ruta, producto)).then(datos)
  },
  categorias: () => clienteHttp.get('/gestion/categorias').then(datos),
  crearCategoria: (categoria) => clienteHttp.post('/gestion/categorias', categoria).then(datos),
  actualizarCategoria: (id, categoria) => clienteHttp.put(`/gestion/categorias/${id}`, categoria).then(datos),
}
