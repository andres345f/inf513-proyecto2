import { useState } from 'react'
import { comercioApi } from '../../api/comercioApi'
import FormularioTarjeta from '../../components/comercio/FormularioTarjeta'
import Alerta from '../ui/Alerta'
import Boton from '../ui/Boton'
import { TARJETA_VACIA, tarjetaParaEnviar, validarTarjeta } from '../../utils/tarjeta'

/**
 * Carga de una tarjeta nueva (compartido): lo usa la página /metodos-pago y
 * el modal que se abre desde Mi cuenta y desde el checkout.
 *
 * - alGuardado(texto): la página decide qué hacer tras guardar (avisar y
 *   recargar su lista).
 */
export default function FormularioNuevaTarjeta({ alGuardado }) {
  const [tarjeta, setTarjeta] = useState(TARJETA_VACIA)
  const [errores, setErrores] = useState({})
  const [mensaje, setMensaje] = useState(null)
  const [guardando, setGuardando] = useState(false)

  const guardar = async (evento) => {
    evento.preventDefault()
    const erroresCliente = validarTarjeta(tarjeta)
    setErrores(erroresCliente)
    if (Object.keys(erroresCliente).length) return
    setGuardando(true)
    setMensaje(null)
    try {
      await comercioApi.agregarMetodoPago(tarjetaParaEnviar(tarjeta))
      setTarjeta(TARJETA_VACIA)
      setErrores({})
      setMensaje({ tipo: 'exito', texto: 'Guardamos tu tarjeta de forma segura.' })
      alGuardado?.('Guardamos tu tarjeta de forma segura.')
    } catch (falla) {
      setErrores(Object.fromEntries(Object.entries(falla.errores || {}).map(([c, m]) => [c, m[0]])))
      setMensaje({ tipo: 'error', texto: falla.mensaje })
    } finally {
      setGuardando(false)
    }
  }

  return (
    <form className="metodos-pago__formulario" onSubmit={guardar} noValidate aria-label="Agregar tarjeta">
      {mensaje && <Alerta tipo={mensaje.tipo}>{mensaje.texto}</Alerta>}
      <FormularioTarjeta valores={tarjeta} errores={errores} alCambiar={setTarjeta} conAlias />
      <Boton tipo="submit" bloque cargando={guardando}>Guardar tarjeta</Boton>
    </form>
  )
}
