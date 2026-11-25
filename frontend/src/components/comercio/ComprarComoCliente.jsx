import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useSesion } from '../../hooks/useSesion'
import Boton from '../ui/Boton'
import Alerta from '../ui/Alerta'

export default function ComprarComoCliente() {
  const { sesion, cambiarRol } = useSesion()
  const navegar = useNavigate()
  const [enviando, setEnviando] = useState(false)
  const [error, setError] = useState('')
  const cliente = sesion?.roles.find((rol) => rol.codigo === 'CLIENTE')
  if (!cliente || cliente.id === sesion.rol_activo.id) return null

  async function seleccionar() {
    setEnviando(true)
    setError('')
    try {
      await cambiarRol(cliente.id)
      navegar('/catalogo')
    } catch (falla) {
      setError(falla.mensaje)
    } finally {
      setEnviando(false)
    }
  }

  return (
    <div>
      <p>Para comprar, usa tu perfil de Cliente.</p>
      <Boton cargando={enviando} onClick={seleccionar}>Comprar como cliente</Boton>
      {error && <Alerta tipo="error">{error}</Alerta>}
    </div>
  )
}
