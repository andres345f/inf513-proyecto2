import { useCallback, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { ChevronDown, LogIn, LogOut, UserPlus } from 'lucide-react'
import { useClicFuera } from '../../hooks/useClicFuera'
import { useSesion } from '../../hooks/useSesion'
import { useNavegacion } from '../../hooks/useNavegacion'
import { nombreRol } from '../../utils/formato'
import './MenuUsuario.css'

/** Acceso para visitantes, o datos del usuario con cambio de rol activo y cierre de sesión. */
export default function MenuUsuario({ compacto }) {
  const { sesion, cambiarRol, cerrarSesion } = useSesion()
  const { entradas } = useNavegacion()
  const botonMenu = useRef(null)
  const [cambiando, setCambiando] = useState(false)
  const [abierto, setAbierto] = useState(false)
  const [error, setError] = useState(null)
  const contenedor = useRef(null)
  const navegar = useNavigate()

  const cerrar = useCallback(() => setAbierto(false), [])
  useClicFuera(contenedor, abierto, cerrar)

  if (!sesion) {
    return (
      <div className="menu-usuario menu-usuario--visitante">
        <Link to="/ingresar" className="menu-usuario__enlace">
          <LogIn size={18} aria-hidden="true" /> Iniciar sesión
        </Link>
        {!compacto && <Link to="/registro" className="menu-usuario__enlace menu-usuario__enlace--destacado">
          <UserPlus size={18} aria-hidden="true" /> Crear cuenta
        </Link>}
      </div>
    )
  }

  const alCambiarRol = async (rol) => {
    setError(null)
    setCambiando(true)
    try {
      await cambiarRol(rol.id)
      cerrar()
      navegar(rol.codigo === 'CLIENTE' ? '/catalogo' : '/')
    } catch (falla) {
      setError(falla.mensaje)
    } finally { setCambiando(false) }
  }

  const alCerrarSesion = async () => {
    cerrar()
    // Primero se sale de la página privada; si no, su ruta protegida enviaría al login.
    navegar('/', { replace: true })
    await cerrarSesion()
  }

  return (
    <div className="menu-usuario" ref={contenedor} onKeyDown={(e) => {
      if (e.key === 'Escape' && abierto) { e.preventDefault(); e.stopPropagation(); cerrar(); botonMenu.current?.focus() }
    }}>
      <button type="button" ref={botonMenu} className="menu-usuario__boton" aria-expanded={abierto} aria-controls="menu-usuario-panel"
        aria-label={`Menú de ${sesion.usuario.nombres}, rol ${nombreRol(sesion.rol_activo.codigo)}`}
        onClick={() => setAbierto((valor) => !valor)}>
        <span className="menu-usuario__avatar" aria-hidden="true">{sesion.usuario.nombres.charAt(0)}</span>
        <span className="menu-usuario__identidad">
          <span className="menu-usuario__nombre">{sesion.usuario.nombres}</span>
          <span className="menu-usuario__rol">{nombreRol(sesion.rol_activo.codigo)}</span>
        </span>
        <ChevronDown size={16} aria-hidden="true" />
      </button>

      {abierto && (
        <div id="menu-usuario-panel" className="menu-usuario__panel">

          {sesion.roles.length > 1 && (
            <fieldset className="menu-usuario__roles">
              <legend>Trabajar como</legend>
              {sesion.roles.map((rol) => (
                <button key={rol.id} type="button" className="menu-usuario__rol-opcion"
                  disabled={cambiando} aria-pressed={rol.id === sesion.rol_activo.id}
                  onClick={() => rol.id !== sesion.rol_activo.id && alCambiarRol(rol)}>
                  {rol.codigo === 'CLIENTE' && rol.id !== sesion.rol_activo.id ? 'Comprar como cliente' : nombreRol(rol.codigo)}
                </button>
              ))}
              {error && <p className="menu-usuario__error" role="alert">{error}</p>}
            </fieldset>
          )}

          {entradas.some((e) => e.ruta === '/perfil') && <Link to="/perfil" className="menu-usuario__opcion" onClick={cerrar}>Mi perfil</Link>}
          <button type="button" className="menu-usuario__opcion menu-usuario__opcion--salir" onClick={alCerrarSesion}>
            <LogOut size={16} aria-hidden="true" /> Cerrar sesión
          </button>
        </div>
      )}
    </div>
  )
}
