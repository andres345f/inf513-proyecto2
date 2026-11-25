import { useCallback, useLayoutEffect, useRef, useState } from 'react'
import { erroresDeApi, validar } from '../utils/validaciones'

/**
 * Estado y validación de un formulario.
 * - Valida en el cliente antes de enviar (mensajes en español).
 * - Si la API rechaza los datos, muestra sus errores en cada campo.
 */
export function useFormulario(valoresIniciales, reglas) {
  const [valores, setValores] = useState(valoresIniciales)
  const [errores, setErrores] = useState({})
  const [errorGeneral, setErrorGeneral] = useState(null)
  const [enviando, setEnviando] = useState(false)
  const [revisionFallida, setRevisionFallida] = useState(0)
  const formulario = useRef(null)

  // El 422 llega de forma asíncrona: el campo solo es localizable después de aplicar los errores al DOM.
  useLayoutEffect(() => {
    if (revisionFallida) formulario.current?.querySelector('[aria-invalid="true"]')?.focus()
  }, [revisionFallida])

  const cambiar = useCallback((evento) => {
    const { name, type, checked, value } = evento.target
    setValores((actual) => ({ ...actual, [name]: type === 'checkbox' ? checked : value }))
    setErrores((actual) => (actual[name] ? { ...actual, [name]: null } : actual))
  }, [])

  const validarCampo = useCallback((evento) => {
    const { name } = evento.target
    if (!reglas[name]) return
    const error = validar(valores, { [name]: reglas[name] })[name] || null
    setErrores((actual) => ({ ...actual, [name]: error }))
  }, [reglas, valores])

  const enviar = useCallback((alEnviar) => async (evento) => {
    evento.preventDefault()
    formulario.current = evento.currentTarget
    setErrorGeneral(null)

    const erroresCliente = validar(valores, reglas)
    setErrores(erroresCliente)
    if (Object.keys(erroresCliente).length > 0) {
      setErrorGeneral('Revisa los campos marcados.')
      setRevisionFallida((conteo) => conteo + 1)
      return
    }

    setEnviando(true)
    try {
      await alEnviar(valores)
    } catch (falla) {
      const erroresServidor = erroresDeApi(falla)
      setErrores(erroresServidor)
      setErrorGeneral(falla.mensaje)
      setRevisionFallida((conteo) => conteo + 1)
    } finally {
      setEnviando(false)
    }
  }, [valores, reglas])

  return { valores, errores, errorGeneral, enviando, cambiar, validarCampo, enviar }
}
