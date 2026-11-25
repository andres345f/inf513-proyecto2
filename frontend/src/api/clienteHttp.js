import axios from 'axios'
import { entorno } from '../config/entorno'
import { borrar, borrarSesion, guardarSesion, leer, leerSesion } from '../utils/almacenamiento'

const CLAVE_TOKEN = 'feder:token'

export const EVENTO_SESION_EXPIRADA = 'feder:sesion-expirada'

export const almacenToken = {
  obtener: () => {
    const tokenSesion = leerSesion(CLAVE_TOKEN)
    if (tokenSesion) return tokenSesion

    // Migra una sesión anterior desde localStorage una sola vez y elimina la copia persistente.
    const tokenAnterior = leer(CLAVE_TOKEN)
    if (tokenAnterior) {
      guardarSesion(CLAVE_TOKEN, tokenAnterior)
      borrar(CLAVE_TOKEN)
    }
    return tokenAnterior
  },
  guardar: (token) => {
    guardarSesion(CLAVE_TOKEN, token)
    borrar(CLAVE_TOKEN)
  },
  limpiar: () => {
    borrarSesion(CLAVE_TOKEN)
    borrar(CLAVE_TOKEN)
  },
}

/** Cliente HTTP único hacia la API REST de Laravel. */
export const clienteHttp = axios.create({
  baseURL: entorno.apiUrl,
  timeout: 20000,
  headers: { Accept: 'application/json' },
})

clienteHttp.interceptors.request.use((config) => {
  const token = almacenToken.obtener()
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

clienteHttp.interceptors.response.use(
  (respuesta) => respuesta,
  (error) => {
    if (error.response?.status === 401 && almacenToken.obtener()) {
      almacenToken.limpiar()
      window.dispatchEvent(new Event(EVENTO_SESION_EXPIRADA))
    }
    return Promise.reject(normalizarError(error))
  },
)

/**
 * Convierte cualquier falla en { estado, mensaje, errores } con textos en español.
 * La API ya responde en español; aquí se cubren los casos sin respuesta del servidor.
 */
function normalizarError(error) {
  if (axios.isCancel(error)) {
    return { estado: 0, mensaje: 'Solicitud cancelada.', errores: {}, cancelado: true }
  }

  if (!error.response) {
    const mensaje = error.code === 'ECONNABORTED'
      ? 'El servidor tardó demasiado en responder. Inténtalo de nuevo.'
      : 'No se pudo conectar con el servidor. Revisa tu conexión a internet.'
    return { estado: 0, mensaje, errores: {} }
  }

  const { status, data } = error.response

  // El proxy de Vite responde HTML cuando el backend está caído: trátalo como "sin servidor".
  if (!data || typeof data !== 'object') {
    return { estado: status, mensaje: 'No se pudo conectar con el servidor. Verifica que el backend esté corriendo.', errores: {} }
  }

  const primerError = Object.values(data?.errors || {})[0]?.[0]
  const mensaje = primerError || data?.message
  if (mensaje) {
    return { estado: status, mensaje, errores: data?.errors || {} }
  }

  // Sin mensaje del servidor: decimos la causa según el código HTTP.
  if (status === 401) return { estado: status, mensaje: 'Tu sesión expiró. Vuelve a iniciar sesión.', errores: {} }
  if (status === 403) return { estado: status, mensaje: 'No tienes permiso para hacer esta acción con tu rol actual.', errores: {} }
  if (status === 404) return { estado: status, mensaje: 'No encontramos lo que pediste (la ruta o el recurso no existe).', errores: {} }
  if (status === 422) return { estado: status, mensaje: 'Los datos enviados no son válidos. Revisa el formulario.', errores: {} }
  if (status >= 500) return { estado: status, mensaje: 'El servidor tuvo un problema interno. Inténtalo de nuevo más tarde.', errores: {} }
  return { estado: status, mensaje: `No se pudo completar la solicitud (error ${status}).`, errores: {} }
}
