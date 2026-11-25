import { CircleAlert, CircleCheck, Info, TriangleAlert } from 'lucide-react'
import './Alerta.css'

/** Mensaje destacado. tipo: error | exito | info | aviso */
const ICONOS = { error: CircleAlert, exito: CircleCheck, aviso: TriangleAlert, info: Info }

export default function Alerta({ tipo = 'info', titulo, children }) {
  const rol = tipo === 'error' ? 'alert' : 'status'
  const Icono = ICONOS[tipo] ?? Info
  return (
    <div className={`alerta alerta--${tipo}`} role={rol}>
      <Icono size={20} aria-hidden="true" />
      <div>{titulo && <strong className="alerta__titulo">{titulo}</strong>}{children}</div>
    </div>
  )
}
