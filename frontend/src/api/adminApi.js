import { clienteHttp } from './clienteHttp'

const datos = (r) => r.data

/** Administración de usuarios y roles (CU1). */
export const adminApi = {
  usuarios: (filtros) => clienteHttp.get('/admin/usuarios', { params: filtros }).then(datos),
  crearUsuario: (usuario) => clienteHttp.post('/admin/usuarios', usuario).then(datos),
  cambiarActivo: (id, activo) => clienteHttp.patch(`/admin/usuarios/${id}/activo`, { activo }).then(datos),
  asignarRol: (id, rol) => clienteHttp.post(`/admin/usuarios/${id}/roles`, { rol }).then(datos),
  revocarRol: (id, rol, motivo) => clienteHttp.post(`/admin/usuarios/${id}/roles/${rol}/revocar`, { motivo }).then(datos),
}

/** Matriz de permisos por rol; cada permiso habilita entradas del menú. */
export const permisosApi = {
  matriz: () => clienteHttp.get('/admin/permisos').then(datos),
  guardar: (rol, permisos) => clienteHttp.put(`/admin/permisos/${rol}`, { permisos }).then(datos),
}

/** Estado del sistema (API y conexión a la base de datos). */
export const sistemaApi = {
  ping: () => clienteHttp.get('/ping').then(datos),
}
