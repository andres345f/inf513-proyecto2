import { clienteHttp } from './clienteHttp'

export const autenticacionApi = {
  iniciarSesion: (credenciales) => clienteHttp.post('/auth/login', credenciales).then((r) => r.data),
  registrarse: (datos) => clienteHttp.post('/auth/registro', datos).then((r) => r.data),
  obtenerSesion: () => clienteHttp.get('/auth/yo').then((r) => r.data),
  actualizarPerfil: (datos) => clienteHttp.put('/auth/perfil', datos).then((r) => r.data),
  /** PUT /api/auth/contrasena; cierra las demás sesiones de la cuenta. */
  cambiarContrasena: (datos) => clienteHttp.put('/auth/contrasena', datos).then((r) => r.data),
  cambiarRol: (rolId) => clienteHttp.post('/auth/rol-activo', { rol_id: rolId }).then((r) => r.data),
  cerrarSesion: () => clienteHttp.post('/auth/logout').then((r) => r.data),
}
